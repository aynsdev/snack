<?php

use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Http\Controllers\Api\V1\Notifications\NotificationPreferenceController;
use App\Http\Controllers\Notifications\NotificationReadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| The notification centre and the member's own preferences for this
| workspace. Marking one notification read is the web's own JSON endpoint.
|
*/

Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');

Route::patch('notifications/read', [NotificationController::class, 'markAllRead'])
    ->name('notifications.read.update');

Route::post('notifications/{notification}/read', [NotificationReadController::class, 'store'])
    ->middleware('throttle:message-reads')
    ->name('notifications.read.store');

Route::get('notification-preferences', [NotificationPreferenceController::class, 'edit'])
    ->name('notification-preferences.edit');
Route::patch('notification-preferences', [NotificationPreferenceController::class, 'update'])
    ->name('notification-preferences.update');
