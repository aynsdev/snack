<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Emailing a password reset link. The link opens the web app's reset form.
 *
 * Answers the same way whether or not the address has an account, so the
 * endpoint cannot be used to find out who does.
 */
class PasswordResetLinkController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => __('If that address has an account, a reset link is on its way.'),
        ], 202);
    }
}
