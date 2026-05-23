<?php

namespace TrackAnyDevice\SsoServer\Http\Controllers;

use TrackAnyDevice\Core\Enums\OAuthClientKind;
use TrackAnyDevice\SsoServer\Models\OAuthClient;
use TrackAnyDevice\Core\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps Passport's AuthorizationController with multi-tenant access checks.
 *
 * Flow:
 *   1. Resolve the OAuthClient by client_id and verify it is active.
 *   2. If unauthenticated, stash the authorize URL for Fortify's
 *      LoginResponse to bounce back after 2FA.
 *   3. For tenant-kind clients, verify the user belongs to that tenant.
 *   4. Delegate to Passport — it issues an authorization code and redirects
 *      to redirect_uri?code=AUTH_CODE&state=STATE.
 *   5. The Socialite client on the tenant host exchanges the code for an
 *      access token via POST /oauth/token (Passport handles this).
 */
class OAuthAuthorizeController extends AuthorizationController
{
    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse,
    ): Response|AuthorizationViewResponse {
        $clientId = $request->query('client_id');

        // No OAuth flow in progress and user is already logged in → send them
        // to my.{domain} rather than showing an error or blank screen.
        if (! $clientId && $request->user()) {
            $myDomain = config('sso-server.my_domain') ?: ('my.'.config('sso-server.app_domain', 'track-any-device.com'));
            return redirect('https://'.$myDomain);
        }

        $client = OAuthClient::where('client_id', $clientId)
            ->where('is_active', true)
            ->first();

        if (! $client) {
            abort(400, 'Unknown or inactive OAuth client.');
        }

        $user = $request->user();

        if (! $user) {
            $request->session()->put('url.intended', $request->fullUrl());
            $request->session()->put('sso.client_id', $client->id);

            return redirect()->route('login');
        }

        if ($client->kind === OAuthClientKind::Admin
            && ! $user->role?->isCentralStaff()
        ) {
            auth()->logout();
            return redirect()->route('login')
                ->withErrors(['email' => 'You do not have admin access.']);
        }

        if ($client->kind === OAuthClientKind::Tenant
            && ! $this->userCanAccessTenantClient($user, $client)
        ) {
            return redirect()->route('tenant.select')
                ->with('errors_login', 'You are not authorised for that organisation.');
        }

        $request->session()->forget(['sso.client_id', 'url.intended']);

        // OAuthClient::skipsAuthorization() returns true, so Passport skips
        // the consent screen and immediately issues the authorization code,
        // redirecting to redirect_uri?code=AUTH_CODE&state=STATE.
        return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
    }

    private function userCanAccessTenantClient(User $user, OAuthClient $client): bool
    {
        if ($user->role?->isCentralStaff()) {
            return true;
        }

        if (! $client->tenant_id) {
            return false;
        }

        return DB::connection(config('tenancy.database.central_connection'))
            ->table('tenant_users')
            ->where('user_id', $user->id)
            ->where('tenant_id', $client->tenant_id)
            ->exists();
    }
}
