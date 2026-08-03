<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToPendingInvitation;
use App\Services\RegistrationGate;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Responses\LoginResponse as FortifyLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone who already has an account and followed an invitation link is sent
 * back to it after signing in, instead of into whichever workspace they were
 * already a member of.
 */
class LoginResponse extends FortifyLoginResponse
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
