<?php

namespace TrackAnyDevice\SsoServer;

use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Responses\SimpleViewResponse;
use Laravel\Passport\Passport;
use TrackAnyDevice\SsoServer\Bridge\ClientRepository as BridgeClientRepository;
use TrackAnyDevice\SsoServer\Models\OAuthClient;
use TrackAnyDevice\SsoServer\Observers\TenantObserver;
use TrackAnyDevice\Core\Models\Tenant;

class SsoServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sso-server.php', 'sso-server');

        // OAuthClient::skipsAuthorization() returns true so the consent view
        // is never rendered, but the container still needs a binding for the
        // AuthorizationViewResponse type hint in OAuthAuthorizeController.
        $this->app->bind(AuthorizationViewResponse::class, function () {
            return new SimpleViewResponse('passport::authorize');
        });
    }

    public function boot(): void
    {
        // Register TenantObserver so every new Tenant automatically gets
        // a paired OAuthClient row on creation.
        Tenant::observe(TenantObserver::class);

        // Tell Passport to use our extended client model so it reads from
        // our existing `oauth_clients` table with the tenant metadata intact.
        Passport::useClientModel(OAuthClient::class);

        // Key files are written by start.sh from env vars — skip the
        // trigger_error permission warning that Laravel converts to an exception.
        Passport::$validateKeyPermissions = false;

        // Override Passport's Bridge\ClientRepository (used by the OAuth2 server
        // for token issuance) and the Eloquent ClientRepository (used by
        // AuthorizationController for skipsAuthorization, findActive, etc.) so
        // both resolve clients by our client_id column, not the UUID id column.
        $this->app->bind(\Laravel\Passport\Bridge\ClientRepository::class, BridgeClientRepository::class);
        $this->app->singleton(\Laravel\Passport\ClientRepository::class, ClientRepository::class);


        // ── OAuth2 scope registry ─────────────────────────────────────────────
        // Every scope a client may request must be declared here. Passport
        // rejects any scope not in this list with invalid_scope.
        //
        // Standard OpenID Connect scopes (identity layer):
        //   openid   — required for OIDC; grants an id_token
        //   profile  — user's display name and avatar
        //   email    — user's email address
        //   role     — user's platform role (admin / staff / tenant_user / user)
        //
        // Platform scopes (critical operations):
        //   fleet:read   — read devices, signals, beats, incidents, assignees
        //   fleet:write  — create / update fleet data (devices, beats, incidents)
        //   admin        — access to the Filament admin panel (admin clients only)
        Passport::tokensCan([
            'openid'      => 'OpenID Connect identity',
            'profile'     => 'User profile (name, avatar)',
            'email'       => 'User email address',
            'role'        => 'Platform role',
            'fleet:read'  => 'Read fleet data — devices, signals, beats, incidents',
            'fleet:write' => 'Manage fleet data — create and update devices, beats, incidents',
            'admin'       => 'Admin panel access',
        ]);

        // Default scopes granted when a client requests no specific scopes.
        Passport::setDefaultScope(['openid', 'profile', 'email', 'role']);

        // Access tokens must outlive the session that carries them.
        // Socialite-based surfaces (admin, tenant) exchange the token
        // immediately; the Next.js My portal stores it as a Bearer token
        // for the entire session lifetime (24 h). Refresh tokens are kept
        // for 30 days to support silent renewal.
        Passport::tokensExpireIn(now()->addHours(24));
        Passport::refreshTokensExpireIn(now()->addDays(30));

        // Passport v12+ auto-registers all OAuth2 routes (token exchange,
        // client management, personal-access tokens) via its own service
        // provider — no Passport::routes() call needed. Our custom
        // OAuthAuthorizeController is registered via SsoServer::routes()
        // so tenant-access checks run before Passport issues the code.

        $this->publishes([
            __DIR__.'/../config/sso-server.php' => config_path('sso-server.php'),
        ], 'sso-server-config');

        // Migrations are owned exclusively by the core/cli app.
        // Non-core surfaces (login, my, admin, tenant) must never run migrations.
        if (in_array(config('sso-server.surface', 'core'), ['core', null], true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
