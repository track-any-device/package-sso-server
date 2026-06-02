<?php

namespace TrackAnyDevice\SsoServer\Models;

use TrackAnyDevice\Core\Concerns\UsesCentralConnection;
use TrackAnyDevice\Core\Enums\OAuthClientKind;
use TrackAnyDevice\Core\Models\Tenant;
use TrackAnyDevice\Core\Models\SsoToken;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Passport\Client as PassportClient;

/**
 * OAuth2 / SSO client — extends Laravel Passport's Client so Passport can
 * use our `oauth_clients` table for the authorization-code grant whilst we
 * retain our tenant-specific metadata (kind, tenant_id, redirect_uris, etc.).
 *
 * Schema compatibility layer:
 *   - `secret`   → backed by `client_secret_hash` (bcrypt via Passport hashing)
 *   - `redirect` → virtual; returns `redirect_uris` JSON array as newline string
 *   - `revoked`  → virtual; derived from `!is_active`
 */
class OAuthClient extends PassportClient
{
    use UsesCentralConnection;

    protected $table = 'oauth_clients';

    protected $fillable = [
        'name',
        'kind',
        'tenant_id',
        'client_id',
        'client_secret_hash',
        'redirect_uris',
        'logout_webhook_url',
        'is_active',
        // Passport-required
        'user_id',
        'secret',
        'personal_access_client',
        'password_client',
        'revoked',
    ];

    protected $hidden = [
        'client_secret_hash',
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'kind' => OAuthClientKind::class,
            'redirect_uris' => 'array',
            'is_active' => 'boolean',
            'personal_access_client' => 'boolean',
            'password_client' => 'boolean',
            'revoked' => 'boolean',
        ];
    }

    /**
     * confidential() checks getAttributes()['secret'] — our secret lives in
     * client_secret_hash so we override to check that column directly.
     */
    public function confidential(): bool
    {
        return ! empty($this->attributes['client_secret_hash'] ?? null);
    }

    /**
     * Passport reads `redirect` (newline-delimited string) when validating
     * redirect_uri in the authorization request. We derive it from our JSON
     * `redirect_uris` array so both systems stay in sync.
     */
    public function getRedirectAttribute(): string
    {
        return implode("\n", $this->redirect_uris ?? []);
    }

    /**
     * Passport writes `secret` as a bcrypt-hashed value. We alias it to
     * `client_secret_hash` so our existing verifySecret() method keeps
     * working while Passport can also verify via its own path.
     */
    public function getSecretAttribute(): ?string
    {
        return $this->attributes['client_secret_hash'] ?? null;
    }

    public function setSecretAttribute(?string $value): void
    {
        $this->attributes['client_secret_hash'] = $value;
    }

    /**
     * Passport checks `revoked` to discard invalidated clients. We invert
     * our `is_active` flag to satisfy that contract.
     */
    public function getRevokedAttribute(): bool
    {
        return ! ($this->attributes['is_active'] ?? true);
    }

    /**
     * When Passport revokes a client it writes `revoked = true` directly.
     * Mirror that into `is_active` so the two columns never diverge.
     */
    public function setRevokedAttribute(bool $value): void
    {
        $this->attributes['revoked']   = $value;
        $this->attributes['is_active'] = ! $value;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<SsoToken, $this> */
    public function ssoTokens(): HasMany
    {
        return $this->hasMany(SsoToken::class, 'oauth_client_id');
    }

    /**
     * Skip Passport's consent screen — our OAuthAuthorizeController already
     * enforces tenant access, so the user has implicitly approved the SSO.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @param  \League\OAuth2\Server\Entities\ScopeEntityInterface[]  $scopes
     */
    public function skipsAuthorization($user, array $scopes): bool
    {
        return true;
    }

    public function permitsRedirectUri(string $url): bool
    {
        return in_array($url, $this->redirect_uris ?? [], true);
    }

    public static function generatePlainSecret(): string
    {
        return 'tcs_'.Str::random(48);
    }

    public static function generateClientId(OAuthClientKind $kind): string
    {
        $prefix = match ($kind) {
            OAuthClientKind::Web     => 'tci_web_',
            OAuthClientKind::My      => 'tci_my_',
            OAuthClientKind::Admin   => 'tci_admin_',
            OAuthClientKind::GraphQl => 'tci_graphql_',
            OAuthClientKind::Tenant  => 'tci_tenant_',
            OAuthClientKind::Mobile  => 'tci_mobile_',
        };

        return $prefix.Str::random(32);
    }
}
