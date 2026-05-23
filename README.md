# track-any-device/sso-server

OAuth 2.0 authorization-code SSO server for the Track Any Device platform.  
Wraps Laravel Passport to issue short-lived authorization codes that Socialite clients exchange for access tokens, with multi-tenant access control baked in.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.3 |
| Laravel | ^13.7 |
| Laravel Passport | ^13.0 |
| track-any-device/core | ^0.0.2 |

---

## Installation

```bash
composer require track-any-device/sso-server
```

Publish the package config:

```bash
php artisan vendor:publish --tag=sso-server-config
```

---

## Environment variables

Add these to the `.env` of whichever surface runs the identity / login host:

| Variable | Default | Description |
|---|---|---|
| `APP_SURFACE` | `core` | Surface name. Migrations only run when this is `core`. Values: `core \| login \| my \| admin \| tenant` |
| `APP_DOMAIN` | `track-any-device.com` | Root domain used to derive all sub-domain URLs |
| `MY_DOMAIN` | `my.{APP_DOMAIN}` | Hostname of the end-user "my" app |
| `LOGIN_DOMAIN` | _(current host)_ | Hostname of the dedicated identity / login surface. Leave unset for single-host deploys |

---

## Migrations

Migrations are loaded automatically when `APP_SURFACE=core` (or is unset). They create:

- `oauth_clients` — SSO client registry (extended Passport client table)
- `sso_tokens` — audit log of issued / consumed tokens
- Passport token tables (`oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_device_codes`) with `client_id` columns widened to `varchar(100)` for `tci_*` prefixed identifiers

---

## Seeding

Seed the singleton clients for the `web`, `my`, `admin`, and `graphql` surfaces:

```bash
php artisan db:seed --class="TrackAnyDevice\SsoServer\Database\Seeders\OAuthClientSeeder"
```

On first run the plain-text secret is printed to stdout once — copy it into the relevant `client_secret` env var on the consuming surface. To rotate a secret, delete the row and re-run.

---

## Route registration

### Web routes (login surface)

Register inside your `login.{domain}` route group (web middleware, Fortify session):

```php
// routes/web.php or routes/auth.php
use TrackAnyDevice\SsoServer\SsoServer;

Route::middleware(['web'])->group(function () {
    SsoServer::routes();
    // Registers: GET oauth/authorize → OAuthAuthorizeController (oauth.authorize)
});
```

### API routes (token-protected user info endpoint)

Register inside an `auth:api` middleware group:

```php
// routes/api.php
use TrackAnyDevice\SsoServer\SsoServer;

Route::middleware(['auth:api'])->group(function () {
    SsoServer::apiRoutes();
    // Registers: GET api/sso/user → SsoUserController (sso.user)
});
```

---

## Host-app contracts

The host app **must** define the following named routes:

| Route name | Surface | Used when |
|---|---|---|
| `login` | login | Unauthenticated user hits `/oauth/authorize` |
| `orders.index` | my | `Role::User` logs in with no OAuth intent |
| `tenant.select` | login | `tenant_user` belongs to multiple tenants |

The host app **must** bind `Laravel\Fortify\Contracts\LoginResponse` to `TrackAnyDevice\SsoServer\Http\Responses\LoginResponse` in a service provider:

```php
$this->app->singleton(
    \Laravel\Fortify\Contracts\LoginResponse::class,
    \TrackAnyDevice\SsoServer\Http\Responses\LoginResponse::class,
);
```

---

## Auth flow

```
Browser                  login.domain               tenant.domain
  │                           │                           │
  ├─ GET /oauth/authorize?    │                           │
  │    client_id=tci_tenant_… │                           │
  │    response_type=code     │                           │
  │    redirect_uri=…/sso/cb  │                           │
  │    state=…                │                           │
  │──────────────────────────>│                           │
  │                           ├─ Verify client active     │
  │                           ├─ Check user ∈ tenant      │
  │                           ├─ Passport issues auth code│
  │<── 302 redirect_uri?code= │                           │
  │         &state=…          │                           │
  │                           │                           │
  ├─ GET /sso/callback?code=… │                           │
  │──────────────────────────────────────────────────────>│
  │                           │<─ POST /oauth/token       │
  │                           │   (code exchange)         │
  │                           │──────────────────────────>│
  │                           │<── {access_token}         │
  │                           │                           │
  │                           │<─ GET /api/sso/user       │
  │                           │   Bearer {access_token}   │
  │                           │──────────────────────────>│
  │                           │<── {id, name, email, role}│
  │                           │                           │
  │           Socialite logs user in on tenant.domain     │
```

---

## Resources

### OAuthClient

Extends `Laravel\Passport\Client`. Stored in `oauth_clients`.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | Internal primary key |
| `client_id` | varchar(64) | Public OAuth identifier (`tci_*` prefix) |
| `kind` | varchar(16) | `web \| my \| admin \| graphql \| tenant` |
| `tenant_id` | bigint nullable | Set only for `kind=tenant` clients |
| `client_secret_hash` | varchar | bcrypt of the plain secret |
| `redirect_uris` | json | Whitelist of valid redirect URIs |
| `logout_webhook_url` | varchar nullable | Back-channel logout endpoint |
| `is_active` | boolean | Kill-switch |

### SsoToken

Audit log of issued tokens. Never deleted; `consumed_at` marks exchange.

---

## Release workflow convention

Commit messages on `main` determine the version bump automatically:

| Prefix | Bump |
|---|---|
| `feat!:` / `BREAKING CHANGE` | major |
| `feat:` | minor |
| `fix:`, `chore:`, `docs:`, etc. | patch |

A manual bump can be forced via **Actions → Release → Run workflow**.  
The workflow skips if there are no new commits since the last tag.
