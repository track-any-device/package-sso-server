<?php

namespace TrackAnyDevice\SsoServer\Database\Seeders;

use TrackAnyDevice\Core\Enums\OAuthClientKind;
use TrackAnyDevice\SsoServer\Models\OAuthClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Ensures singleton OAuthClient rows exist for web, my, admin, and graphql surfaces.
 *
 * Idempotent behaviour:
 *   - First run  → creates the client with a generated secret (printed once to stdout).
 *   - Re-runs    → updates name, redirect_uris, and is_active without rotating the secret.
 *
 * To rotate a secret: delete the row and re-run the seeder.
 */
class OAuthClientSeeder extends Seeder
{
    public function run(): void
    {
        $appDomain = config('sso-server.cental_domain', 'track-any-device.com');
        $scheme    = str_starts_with((string) config('app.url', ''), 'https') ? 'https' : 'http';

        $singletons = [
            ['kind' => OAuthClientKind::Web,     'label' => 'Web',                 'redirect_uri' => "{$scheme}://{$appDomain}/sso/callback"],
            ['kind' => OAuthClientKind::My,      'label' => 'My (End-user app)',   'redirect_uri' => "{$scheme}://my.{$appDomain}/sso/callback"],
            ['kind' => OAuthClientKind::Admin,   'label' => 'Admin panel',         'redirect_uri' => "{$scheme}://admin.{$appDomain}/sso/callback"],
            ['kind' => OAuthClientKind::GraphQl, 'label' => 'GraphQL Explorer',    'redirect_uri' => "{$scheme}://graphql.{$appDomain}/sso/callback"],
        ];

        foreach ($singletons as $meta) {
            $kind     = $meta['kind'];
            $existing = OAuthClient::where('kind', $kind->value)->first();

            if ($existing) {
                // Update non-secret fields so redirect URIs stay in sync with APP_DOMAIN.
                // The secret is never rotated here — delete the row to rotate.
                $existing->update([
                    'name'          => $meta['label'],
                    'redirect_uris' => [$meta['redirect_uri']],
                    'is_active'     => true,
                ]);
                $this->command?->line("  [updated] {$kind->value}: {$existing->client_id}");
                continue;
            }

            $clientId    = OAuthClient::generateClientId($kind);
            $plainSecret = OAuthClient::generatePlainSecret();

            OAuthClient::create([
                'name'               => $meta['label'],
                'kind'               => $kind,
                'tenant_id'          => null,
                'client_id'          => $clientId,
                'client_secret_hash' => Hash::make($plainSecret),
                'redirect_uris'      => [$meta['redirect_uri']],
                'is_active'          => true,
            ]);

            $this->command?->info("  [created] {$kind->value}: {$clientId}");
            $this->command?->line("            secret: {$plainSecret}");
        }
    }
}
