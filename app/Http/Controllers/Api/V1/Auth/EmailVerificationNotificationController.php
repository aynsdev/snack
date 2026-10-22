<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resending the verification email. Every workspace route needs a verified
 * address, so an unverified account can do little else until it is.
 */
class EmailVerificationNotificationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => __('Your email address is already verified.')]);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => __('A new verification link has been sent.')], 202);
    }
}
