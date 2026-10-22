<?php

use App\Http\Controllers\Api\V1\Account\AvatarController;
use App\Http\Controllers\Api\V1\Account\PasswordController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Account\PushDeviceController;
use App\Http\Controllers\Api\V1\Account\StatusController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Presence\PresenceController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

/*
 * The signed-in person's own account. Outside the workspace prefix for the
 * reason the web app's settings are: a person is one account across every
 * workspace they belong to.
 */
Route::get('me', [ProfileController::class, 'show'])->name('me.show');
Route::patch('me', [ProfileController::class, 'update'])->name('me.update');
Route::delete('me', [ProfileController::class, 'destroy'])->name('me.destroy');

Route::put('me/password', [PasswordController::class, 'update'])->name('me.password.update');
Route::patch('me/status', StatusController::class)->name('me.status.update');
Route::post('me/avatar', [AvatarController::class, 'store'])->middleware('throttle:uploads')->name('me.avatar.store');
Route::delete('me/avatar', [AvatarController::class, 'destroy'])->name('me.avatar.destroy');

// Push notifications — see PushDeviceController.
Route::post('push-devices', [PushDeviceController::class, 'store'])->middleware('throttle:30,1')->name('push-devices.store');
Route::delete('push-devices', [PushDeviceController::class, 'destroy'])->name('push-devices.destroy');

Route::post('auth/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('auth.verification.send');

// Already JSON on the web — see Presence\PresenceController.
Route::post('presence', [PresenceController::class, 'store'])->middleware('throttle:presence')->name('presence.store');
Route::delete('presence', [PresenceController::class, 'destroy'])->middleware('throttle:presence')->name('presence.destroy');

Route::post('invitations/{token}/accept', [InvitationController::class, 'store'])
    ->middleware(['verified', 'throttle:10,1'])
    ->name('invitations.accept');

/*
 * Authorizing private and presence channel subscriptions for the app's Echo
 * client, with the bearer token in place of the web app's cookie. The channel
 * rules are the same ones — routes/channels.php.
 */
Route::match(['get', 'post'], 'broadcasting/auth', [BroadcastController::class, 'authenticate'])
    ->name('broadcasting.auth');
