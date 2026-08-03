<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToPendingInvitation;
use App\Services\RegistrationGate;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse as FortifyTwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two-factor challenge is a second leg of the same sign-in, so it has to
 * honour a pending invitation exactly as {@see LoginResponse} does.
 */
class TwoFactorLoginResponse extends FortifyTwoFactorLoginResponse
{
    use RedirectsToPendingInvitation;

    public function __construct(private readonly RegistrationGate $gate) {}

    /**
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        return $this->pendingInvitationRedirect($request) ?? parent::toResponse($request);
    }
}
