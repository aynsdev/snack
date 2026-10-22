<?php

namespace App\Actions\Api;

use App\Http\Middleware\EnsureMobileToken;
use App\Models\User;
use App\Support\PersonalAccessClient;
use Laravel\Passport\PersonalAccessTokenResult;

/**
 * Issue the bearer token the mobile app signs in with.
 *
 * A personal access token carrying only the `mobile` scope, named after the
 * device so a person can tell their phone's sign-in from their tablet's. The
 * plaintext comes back exactly once, in the returned result.
 */
class IssueMobileToken
{
    public function __construct(private readonly PersonalAccessClient $personalAccessClient) {}

    /**
     * @return PersonalAccessTokenResult<mixed>
     */
    public function handle(User $user, string $deviceName): PersonalAccessTokenResult
    {
        $this->personalAccessClient->ensure();

        return $user->createToken($deviceName, [EnsureMobileToken::SCOPE]);
    }
}
