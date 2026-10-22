<?php

namespace App\Support;

use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * The Passport "personal access" client that personal tokens are issued
 * through — AI connector tokens and the mobile app's tokens alike.
 *
 * Created on demand rather than left as a deploy step, because a forgotten
 * `passport:client --personal` would surface as a 500 on sign-in.
 */
class PersonalAccessClient
{
    public function __construct(private readonly ClientRepository $clients) {}

    public function ensure(): void
    {
        $exists = Passport::client()->newQuery()
            ->where('revoked', false)
            ->get()
            ->contains(fn ($client): bool => $client->hasGrantType('personal_access'));

        if (! $exists) {
            $this->clients->createPersonalAccessGrantClient(config('app.name').' connectors');
        }
    }

    /**
     * Whether the client that issued a token is a personal access client — as
     * opposed to one an OAuth application registered for itself.
     */
    public function issued(?string $clientId): bool
    {
        if ($clientId === null) {
            return false;
        }

        return Passport::client()->newQuery()->find($clientId)?->hasGrantType('personal_access') ?? false;
    }
}
