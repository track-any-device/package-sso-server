<?php

namespace TrackAnyDevice\SsoServer\Bridge;

use Illuminate\Contracts\Hashing\Hasher;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\ClientRepository as ClientModelRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

/**
 * Overrides Passport's default Bridge\ClientRepository so the OAuth2 server
 * resolves clients by our public `client_id` column (tci_my_… / tci_tenant_…)
 * instead of the internal UUID `id` primary key.
 *
 * Passport passes the concrete Bridge\ClientRepository directly to
 * AuthorizationServer — binding this class to that key intercepts both
 * getClientEntity and validateClient.
 */
class ClientRepository implements ClientRepositoryInterface
{
    public function __construct(
        protected ClientModelRepository $clients,
        protected Hasher $hasher,
    ) {}

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $record = Passport::client()
            ->where('client_id', $clientIdentifier)
            ->where('revoked', false)
            ->first();

        if (! $record) {
            return null;
        }

        return new Client(
            $clientIdentifier,
            $record->name,
            $record->redirect_uris ?? [],
            $record->confidential(),
            $record->provider ?? null,
            $record->grant_types ?? ['authorization_code'],
        );
    }

    public function validateClient(string $clientId, ?string $clientSecret, ?string $redirectUri): bool
    {
        $record = Passport::client()
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->first();

        if (! $record) {
            return false;
        }

        if (! $record->confidential()) {
            return true;
        }

        if (! $clientSecret) {
            return false;
        }

        $storedHash = (string) $record->client_secret_hash;
        $incoming   = (string) $clientSecret;

        // Standard OAuth2 clients (e.g. the web Next.js app) send the plain-text
        // secret — verify with bcrypt.
        if ($this->hasher->check($incoming, $storedHash)) {
            return true;
        }

        // Socialite-based server apps (admin, graphql, tenant) read
        // client_secret_hash directly from the DB and forward it as the
        // client_secret — compare hash-to-hash.
        return hash_equals($storedHash, $incoming);
    }
}
