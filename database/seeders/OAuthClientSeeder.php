<?php

namespace TrackAnyDevice\SsoServer\Database\Seeders;

use TrackAnyDevice\Core\Enums\OAuthClientKind;
use TrackAnyDevice\SsoServer\Models\OAuthClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Upserts singleton OAuth clients with STATIC, well-known client IDs.
 *
 * Static IDs mean:
 *   - Fresh installs work immediately without manual client provisioning.
 *   - The web app and mobile app can embed the client_id as a default.
 *   - Re-running the seeder updates redirect URIs to match APP_DOMAIN.
 *
 * Secrets are static dev defaults — override in production via env vars
 * or by deleting the row and re-running with custom env vars set.
 *
 * The mobile client is a PUBLIC (PKCE) client — no secret.
 *
 * Client ID / secret reference (for embedding in consuming apps):
 * ┌─────────────────────────────┬───────────────────────────────┬────────────────────────────────────────┐
 * │ Kind                        │ client_id                     │ client_secret (plaintext)              │
 * ├─────────────────────────────┼───────────────────────────────┼────────────────────────────────────────┤
 * │ Web   (Next.js /sso)        │ tad_web_portal                │ tad_web_portal_secret                  │
 * │ My    (my.* portal)         │ tad_my_portal                 │ tad_my_portal_secret                   │
 * │ Admin (Filament)            │ tad_admin_panel               │ tad_admin_panel_secret                 │
 * │ GraphQL (explorer)          │ tad_graphql_api               │ tad_graphql_api_secret                 │
 * │ Mobile (TAD-101 PKCE)       │ tad_mobile_tad101             │ — (public PKCE client, no secret)      │
 * └─────────────────────────────┴───────────────────────────────┴────────────────────────────────────────┘
 */
class OAuthClientSeeder extends Seeder
{
    /**
     * Static client credentials.
     *
     * These are the ONLY values consuming apps should embed as defaults.
     * Rotate by updating client_secret_hash in the DB and updating env vars
     * in the consuming app — do NOT change the client_id.
     */
    public const WEB_CLIENT_ID     = 'tad_web_portal';
    public const WEB_CLIENT_SECRET = 'tad_web_portal_secret';

    public const MY_CLIENT_ID      = 'tad_my_portal';
    public const MY_CLIENT_SECRET  = 'tad_my_portal_secret';

    public const ADMIN_CLIENT_ID     = 'tad_admin_panel';
    public const ADMIN_CLIENT_SECRET = 'tad_admin_panel_secret';

    public const GRAPHQL_CLIENT_ID     = 'tad_graphql_api';
    public const GRAPHQL_CLIENT_SECRET = 'tad_graphql_api_secret';

    /** Public PKCE client — no secret ever. */
    public const MOBILE_CLIENT_ID = 'tad_mobile_tad101';

    public function run(): void
    {
        $appDomain = config('sso-server.central_domain', 'track-any-device.com');
        $scheme    = str_starts_with((string) config('app.url', ''), 'https') ? 'https' : 'http';

        $clients = [
            // ── Confidential clients ──────────────────────────────────────────
            [
                'client_id'     => self::WEB_CLIENT_ID,
                'plain_secret'  => self::WEB_CLIENT_SECRET,
                'kind'          => OAuthClientKind::Web,
                'label'         => 'Web Portal',
                'redirect_uris' => [
                    // NextAuth v5 callback (current)
                    "{$scheme}://{$appDomain}/api/auth/callback/sso",
                    'http://localhost:3000/api/auth/callback/sso',
                    'http://localhost/api/auth/callback/sso',
                    // Legacy /sso/callback kept for zero-downtime rollout
                    "{$scheme}://{$appDomain}/sso/callback",
                ],
            ],
            [
                'client_id'     => self::MY_CLIENT_ID,
                'plain_secret'  => self::MY_CLIENT_SECRET,
                'kind'          => OAuthClientKind::My,
                'label'         => 'My (End-user app)',
                'redirect_uris' => [
                    "{$scheme}://my.{$appDomain}/sso/callback",
                    'http://localhost:3000/sso/callback',
                    'http://localhost/sso/callback',
                ],
            ],
            [
                'client_id'     => self::ADMIN_CLIENT_ID,
                'plain_secret'  => self::ADMIN_CLIENT_SECRET,
                'kind'          => OAuthClientKind::Admin,
                'label'         => 'Admin Panel',
                'redirect_uris' => [
                    "{$scheme}://admin.{$appDomain}/sso/callback",
                    'http://localhost:3333/sso/callback',
                ],
            ],
            [
                'client_id'     => self::GRAPHQL_CLIENT_ID,
                'plain_secret'  => self::GRAPHQL_CLIENT_SECRET,
                'kind'          => OAuthClientKind::GraphQl,
                'label'         => 'GraphQL Explorer',
                'redirect_uris' => [
                    "{$scheme}://graphql.{$appDomain}/sso/callback",
                ],
            ],

            // ── Public PKCE client (mobile) ───────────────────────────────────
            // No client_secret — PKCE code_verifier proves identity instead.
            [
                'client_id'     => self::MOBILE_CLIENT_ID,
                'plain_secret'  => null,
                'kind'          => OAuthClientKind::Mobile,
                'label'         => 'TAD101 Mobile App',
                'redirect_uris' => [
                    'tad101://callback',
                ],
            ],
        ];

        foreach ($clients as $meta) {
            $existing = OAuthClient::where('client_id', $meta['client_id'])->first();

            if ($existing) {
                $existing->update([
                    'name'          => $meta['label'],
                    'redirect_uris' => $meta['redirect_uris'],
                    'is_active'     => true,
                ]);
                $this->command?->line("  [updated] {$meta['kind']->value}: {$meta['client_id']}");
                continue;
            }

            OAuthClient::create([
                'name'               => $meta['label'],
                'kind'               => $meta['kind'],
                'tenant_id'          => null,
                'client_id'          => $meta['client_id'],
                'client_secret_hash' => $meta['plain_secret'] !== null
                    ? Hash::make($meta['plain_secret'])
                    : null,
                'redirect_uris'      => $meta['redirect_uris'],
                'is_active'          => true,
            ]);

            if ($meta['plain_secret'] !== null) {
                $this->command?->info("  [created] {$meta['kind']->value}: {$meta['client_id']}");
                $this->command?->line("            secret (plaintext): {$meta['plain_secret']}");
            } else {
                $this->command?->info("  [created] {$meta['kind']->value}: {$meta['client_id']} (PKCE — no secret)");
            }
        }

        $this->command?->newLine();
        $this->command?->warn('  ⚠  These are static DEV credentials. Rotate for production:');
        $this->command?->line('     Delete the oauth_clients rows and re-run with custom env vars.');
    }
}
