<?php

namespace App\Mcp;

use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * Passport's client lookup, made safe for a client id that is not a UUID.
 *
 * Client ids are UUIDs, stored in a Postgres `uuid` column, and Passport hands
 * whatever arrived in `?client_id=` straight to that column — so any other
 * string is not "no such client" but a query error and a 500. MCP clients do
 * send other strings: a newer client may identify itself by the URL of a
 * client ID metadata document. That must come back as OAuth's own
 * `invalid_client`, which tells the client to register instead.
 */
class OAuthClientRepository extends ClientRepository
{
    public function find(string|int $id): ?Client
    {
        if (Passport::$clientUuids && ! Str::isUuid((string) $id)) {
            return null;
        }

        return parent::find($id);
    }
}
