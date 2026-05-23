<?php

namespace TrackAnyDevice\SsoServer;

use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository as PassportClientRepository;
use Laravel\Passport\Passport;

/**
 * Overrides Passport's Eloquent ClientRepository so all lookups that
 * use the OAuth2 client identifier (tci_my_… / tci_tenant_…) resolve
 * against our `client_id` column rather than the UUID `id` primary key.
 *
 * Passport's AuthorizationController calls $this->clients->find($identifier)
 * where $identifier = Bridge\Client::getIdentifier() = our client_id value.
 */
class ClientRepository extends PassportClientRepository
{
    public function find(string|int $id): ?Client
    {
        return once(fn () => Passport::client()
            ->newQuery()
            ->where('client_id', $id)
            ->first()
            ?? Passport::client()->newQuery()->find($id)
        );
    }
}
