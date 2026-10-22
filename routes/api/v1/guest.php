<?php

use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\ClientConfigController;
use App\Http\Controllers\Api\V1\InvitationController;
use Illuminate\Support\Facades\Route;

/*
 * Reachable without a token: creating an account, recovering one, and reading
 * the invitation that brought someone here.
 */
Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/register', [RegistrationController::class, 'store'])->name('auth.register');
    Route::post('auth/forgot-password', [PasswordResetLinkController::class, 'store'])->name('auth.forgot-password');
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
});

Route::get('config', ClientConfigController::class)->name('config');
