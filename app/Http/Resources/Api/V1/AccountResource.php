<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in person, as the mobile app sees themselves.
 *
 * {@see UserResource} plus what only the account holder should see — their
 * email and its verification, and whether two-factor is on.
 *
 * @mixin User
 */
class AccountResource extends UserResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
        ];
    }
}
