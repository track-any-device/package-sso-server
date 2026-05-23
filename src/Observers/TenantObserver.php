<?php

namespace TrackAnyDevice\SsoServer\Observers;

use TrackAnyDevice\Core\Enums\OAuthClientKind;
use TrackAnyDevice\Core\Models\Domain;
use TrackAnyDevice\SsoServer\Models\OAuthClient;
use TrackAnyDevice\Core\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Automatically provisions two resources whenever a new Tenant is created:
 *
 *   1. Domain  — {slug}.{APP_DOMAIN}  (primary, is_primary = true)
 *   2. OAuthClient — authorization-code client for the tenant SSO flow
 *
 * Both are idempotent: if the row already exists (e.g. re-running a seeder
 * that uses updateOrCreate), the observer skips creation silently.
 */
class TenantObserver
{
    public function created(Tenant $tenant): void
    {
        $this->provisionDomain($tenant);
        $this->provisionOAuthClient($tenant);
    }

    private function provisionDomain(Tenant $tenant): void
    {
        $parent = $this->parentDomain();

        if (! $parent) {
            Log::warning('TenantObserver: APP_DOMAIN not set — skipping domain creation', [
                'tenant_slug' => $tenant->slug,
            ]);
            return;
        }

        $hostname = $tenant->slug . '.' . $parent;

        Domain::firstOrCreate(
            ['domain' => $hostname],
            ['tenant_id' => $tenant->id, 'is_primary' => true]
        );

        Log::info('Tenant domain provisioned', [
            'tenant_slug' => $tenant->slug,
            'domain'      => $hostname,
        ]);
    }

    private function provisionOAuthClient(Tenant $tenant): void
    {
        if (OAuthClient::where('tenant_id', $tenant->id)
            ->where('kind', OAuthClientKind::Tenant->value)
            ->exists()
        ) {
            return;
        }

        $clientId    = OAuthClient::generateClientId(OAuthClientKind::Tenant);
        $plainSecret = OAuthClient::generatePlainSecret();

        OAuthClient::create([
            'name'               => $tenant->name,
            'kind'               => OAuthClientKind::Tenant,
            'tenant_id'          => $tenant->id,
            'client_id'          => $clientId,
            'client_secret_hash' => Hash::make($plainSecret),
            'redirect_uris'      => $this->defaultRedirectUrisFor($tenant),
            'is_active'          => true,
        ]);

        Log::info('Tenant SSO client provisioned', [
            'tenant_id'           => $tenant->id,
            'tenant_slug'         => $tenant->slug,
            'client_id'           => $clientId,
            'client_secret_plain' => $plainSecret,
        ]);
    }

    private function parentDomain(): ?string
    {
        $domain = config('app.domain');

        if (is_string($domain) && $domain !== '') {
            return $domain;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /** @return array<int, string> */
    private function defaultRedirectUrisFor(Tenant $tenant): array
    {
        $parent = $this->parentDomain();
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        if (! $parent) {
            return [];
        }

        return [$scheme . '://' . $tenant->slug . '.' . $parent . '/sso/callback'];
    }
}
