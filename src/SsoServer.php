<?php

namespace TrackAnyDevice\SsoServer;

use Illuminate\Support\Facades\Route;
use TrackAnyDevice\SsoServer\Http\Controllers\OAuthAuthorizeController;
use TrackAnyDevice\SsoServer\Http\Controllers\SsoUserController;

/**
 * Register the SSO server routes within the calling route group.
 *
 * Usage in routes/web.php / routes/auth.php:
 *
 *   // Inside your login-domain web middleware group:
 *   SsoServer::routes();
 *
 *   // Inside your api middleware group (auth:api guard):
 *   SsoServer::apiRoutes();
 *
 * Routes registered by routes():
 *   GET  oauth/authorize  → OAuthAuthorizeController  (oauth.authorize)
 *
 * Routes registered by apiRoutes():
 *   GET  api/sso/user     → SsoUserController          (sso.user)
 *   Must be protected by the `auth:api` middleware in the host app.
 */
class SsoServer
{
    public static function routes(): void
    {
        Route::get('oauth/authorize', [OAuthAuthorizeController::class, 'authorize'])
            ->name('oauth.authorize');
    }

    public static function apiRoutes(): void
    {
        Route::get('api/sso/user', SsoUserController::class)
            ->name('sso.user');
    }
}
