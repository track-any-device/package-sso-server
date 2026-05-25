<?php

namespace TrackAnyDevice\SsoServer\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;
use TrackAnyDevice\Core\Enums\OAuthClientKind;
use TrackAnyDevice\Core\Enums\Role;
use TrackAnyDevice\Core\Enums\TenantStatus;
use TrackAnyDevice\Core\Models\User;
use TrackAnyDevice\SsoServer\Models\OAuthClient;

/**
 * Routes a freshly-authenticated user to the right destination.
 *
 *   OAuth intent in session     → bounce back to /oauth/authorize so the
 *                                 mint+redirect step can complete
 *   admin / supervisor / staff  → central /admin (Filament)
 *   tenant_user, one approved   → OAuth flow into that tenant's subdomain
 *   tenant_user, multiple       → /tenant-select
 *   tenant_user, none           → /login with "no tenant" error
 *   user (end customer)         → /orders (on my.{APP_DOMAIN})
 *
 * Cross-host hops (tenant_user → tenant subdomain) go through the OAuth
 * pipeline now — there is no shared session cookie, no Inertia::location
 * 409 dance, and no `?from=` query. The single source of truth for
 * tenant entry is /oauth/authorize.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        $user = $request->user();

        // OAuth authorize flow: the user landed on /login because
        // OAuthAuthorizeController bounced them. After auth completes we
        // must come back to /oauth/authorize so it can mint the token
        // for the requesting client.
        $intended = $request->session()->pull('url.intended');
        if ($intended && $this->isOAuthAuthorizeIntent($intended)) {
            return redirect()->away($intended);
        }

        if ($user->role?->isCentralStaff()) {
            $adminDomain = config('sso-server.admin_domain');
            $adminUrl = $adminDomain
                ? $request->getScheme().'://'.$adminDomain
                : url('/admin');

            return redirect()->away($adminUrl);
        }

        if ($user->role === Role::User) {
            return redirect()->intended(route('orders.index'));
        }

        // tenant_user — route into the tenant via OAuth (single-tenant
        // shortcut), via /tenant-select (multi-tenant picker), or kick
        // them back out (no approved tenant).
        return $this->routeTenantUser($request, $user);
    }

    private function routeTenantUser(Request $request, User $user): Response
    {
        $approvedTenants = $user->tenants()
            ->where('status', TenantStatus::Approved)
            ->get(['tenants.id', 'tenants.slug']);

        if ($approvedTenants->isEmpty()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'No approved organisation is associated with this account.']);
        }

        if ($approvedTenants->count() > 1) {
            return redirect()->route('tenant.select');
        }

        // Single tenant — bounce through OAuth so the tenant subdomain
        // establishes its own session via the SSO callback. The current
        // request is on the central host; the user already has a central
        // session, so OAuthAuthorizeController will issue a token without
        // re-prompting.
        $tenant = $approvedTenants->first();
        $client = OAuthClient::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', OAuthClientKind::Tenant->value)
            ->where('is_active', true)
            ->first();

        if (! $client || empty($client->redirect_uris)) {
            // Defensive — tenant has no SSO client. Send them to the
            // picker so they can at least see their org listed.
            return redirect()->route('tenant.select');
        }

        // Build the authorize URL on the dedicated identity host
        // (login.{APP_DOMAIN}). When LOGIN_DOMAIN is unset we are already
        // serving Fortify on the bare central host, so url() returns the
        // right thing for legacy single-host deploys.
        $loginHost = config('sso-server.login_domain') ?: config('app.domain');
        $base = $loginHost
            ? $request->getScheme().'://'.$loginHost.'/oauth/authorize'
            : url('/oauth/authorize');

        $authorizeUrl = $base.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => $client->redirect_uris[0],
            'state' => Str::random(40),
        ]);

        return redirect()->away($authorizeUrl);
    }

    /**
     * Whether the intended URL points back at the OAuth authorize
     * endpoint. We only honor `url.intended` if it does; otherwise the
     * normal role-based routing wins.
     */
    private function isOAuthAuthorizeIntent(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && trim($path, '/') === 'oauth/authorize';
    }
}
