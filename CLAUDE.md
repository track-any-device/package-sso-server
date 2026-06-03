# package-sso-server — AI Instructions

This is the **OAuth2 identity server package** for the Track Any Device platform.
Packagist: `track-any-device/sso-server` | Namespace: `TrackAnyDevice\SsoServer\`

This package wraps Laravel Passport to provide a centralised OAuth2 authorization server.
It is consumed exclusively by `server-login` — no other server app should depend on it.

Read this file before making any change.

---

## Platform-Wide Rules

These three rules apply in every repository under the `track-any-device` organisation.

**Cross-repo changes: file a GitHub issue first.**
If a task in this repository requires a change in another package or server app — stop. Open a
GitHub issue in the target repository describing exactly what is needed and why. Reference that
issue number in your commit message (`ref track-any-device/{repo}#{n}`). Do not directly edit
files in another repository. When picking up a cross-repo issue, run Claude locally inside that
repository's working directory and work only within its scope.

**Release order: packages before server apps.**
This package depends on `package-core`. Release order: `package-core → package-sso-server → server-login`.
`package-sso-client` (consumed by all other server apps) must NOT depend on this package.

**Database layer lives in `package-core` only.**
The `oauth_clients` and `sso_tokens` tables are managed by migrations in `package-core`.
Do not add migrations here. OAuth client data models belong in `package-core`.

---

## Rule 1 — Plan before implementing

Before writing any code, ask clarifying questions. Present a plan and get explicit agreement.
Only begin once the approach is confirmed.

---

## What lives in this package

| Class/File | Purpose |
|---|---|
| `SsoServer::routes()` | Mounts `/oauth/authorize` and related Passport endpoints |
| `OAuthAuthorizeController` | Validates tenant access before delegating to Passport |
| `SsoUserController` | Serves `/api/sso/user` (guarded by `auth:passport`) |
| `LoginResponse` | Fortify login response — drives OAuth2 redirect after credential auth |
| `SsoServerServiceProvider` | Registers Passport, routes, and response bindings |

---

## Rule 2 — Tenant access check is mandatory before authorizing

`OAuthAuthorizeController` must verify that the authenticated user is a member of the
requested tenant before issuing an authorization code. Never bypass this check.
A user who is not a member of a tenant must receive a 403, not an auth code.

---

## Rule 3 — OAuth clients are seeded, not created ad-hoc

OAuth clients (`oauth_clients` table) are seeded by `OAuthClientSeeder` in `package-core`.
New client kinds must be added as a new `OAuthClientKind` enum case in `package-core`,
followed by a seeder entry. Never create OAuth clients programmatically outside the seeder.

---

## OAuth Client Kinds

| Kind | Prefix | Consumer |
|---|---|---|
| `OAuthClientKind::My` | `tci_my_` | `web/` Next.js "my" portal (NextAuth) |
| `OAuthClientKind::Tenant` | `tci_tenant_` | `server-tenant` (one per tenant slug) |
| `OAuthClientKind::Admin` | `tci_admin_` | `server-admin` |
| `OAuthClientKind::GraphQl` | `tci_graphql_` | `server-graphql` |

---

## Dependencies

```
track-any-device/core
laravel/passport ^13
```

---

## Versioning

Tags are created automatically on merge to `main`. Default bump is `patch`.
