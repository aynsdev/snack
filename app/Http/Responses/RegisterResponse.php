<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToPendingInvitation;
use App\Services\RegistrationGate;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Responses\RegisterResponse as FortifyRegisterResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registration almost always happens because someone was invited, so the
 * account they just created is returned to the invitation rather than to the
 * workspace picker.
 */
class RegisterResponse extends FortifyRegisterResponse
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
