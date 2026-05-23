<?php

namespace TrackAnyDevice\SsoServer\Database\Factories;

use TrackAnyDevice\Core\Enums\OAuthClientKind;
use TrackAnyDevice\SsoServer\Models\OAuthClient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<OAuthClient>
 *
 * Use a state — central() / my() / tenant() — for the concrete kind you want.
 * The bare factory defaults to a tenant-flavoured client because that's the
 * most common case (one per tenant).
 *
 * `withPlainSecret('s3cret')` lets tests assert verifySecret() / Hash::check
 * with a known plaintext.
 */
class OAuthClientFactory extends Factory
{
    protected $model = OAuthClient::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' SSO Client',
            'kind' => OAuthClientKind::Tenant,
            'tenant_id' => null,
            'client_id' => OAuthClient::generateClientId(OAuthClientKind::Tenant),
            'client_secret_hash' => Hash::make(OAuthClient::generatePlainSecret()),
            'redirect_uris' => ['https://'.$this->faker->domainName().'/sso/callback'],
            'logout_webhook_url' => null,
            'is_active' => true,
        ];
    }

    public function central(): static
    {
        return $this->state(fn () => [
            'name' => 'Central marketing',
            'kind' => OAuthClientKind::Web,
            'tenant_id' => null,
            'client_id' => OAuthClient::generateClientId(OAuthClientKind::Web),
            'redirect_uris' => ['https://web.example.com/sso/callback'],
        ]);
    }

    public function my(): static
    {
        return $this->state(fn () => [
            'name' => 'My (end-user app)',
            'kind' => OAuthClientKind::My,
            'tenant_id' => null,
            'client_id' => OAuthClient::generateClientId(OAuthClientKind::My),
            'redirect_uris' => ['https://my.example.com/sso/callback'],
        ]);
    }

    public function tenant(?int $tenantId = null): static
    {
        return $this->state(fn () => [
            'kind' => OAuthClientKind::Tenant,
            'tenant_id' => $tenantId,
            'client_id' => OAuthClient::generateClientId(OAuthClientKind::Tenant),
        ]);
    }

    /**
     * Pin a known plaintext secret so a test can later call verifySecret on it.
     */
    public function withPlainSecret(string $plain): static
    {
        return $this->state(fn () => [
            'client_secret_hash' => Hash::make($plain),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
