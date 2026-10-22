<?php

use App\Http\Controllers\Ai\BiteApprovalController;
use App\Http\Controllers\Api\V1\Messages\MentionController;
use App\Http\Controllers\Api\V1\Messages\SaveController as SavedController;
use App\Http\Controllers\Api\V1\Messages\ThreadController as FollowedThreadController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\Messages\PinController;
use App\Http\Controllers\Messages\ReactionController;
use App\Http\Controllers\Messages\SaveController;
use App\Http\Controllers\Messages\ThreadController;
use App\Http\Controllers\Messages\ThreadReadController;
use App\Http\Controllers\Messages\ThreadSubscriptionController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Messages, threads and uploads
|--------------------------------------------------------------------------
|
| Addressed by message id, which already names its container — so, like the
| web app, nothing here goes through a channel or conversation segment. Most of
| the web controllers here already answer with JSON and are routed to as-is;
| only the three list pages (saved, mentions, threads) have API controllers.
|
| Posting a message — or a thread reply, with `parent_message_id` — goes
| through the container's own `.../messages` endpoint.
|
*/

Route::get('saved', [SavedController::class, 'index'])->name('saved.index');
Route::get('mentions', [MentionController::class, 'index'])->name('mentions.index');
Route::get('threads', [FollowedThreadController::class, 'index'])->name('threads.index');

Route::patch('messages/{message}', [MessageController::class, 'update'])
    ->middleware('throttle:messages')
    ->name('messages.update');
Route::delete('messages/{message}', [MessageController::class, 'destroy'])
    ->name('messages.destroy');

Route::get('messages/{message}/thread', [ThreadController::class, 'show'])
    ->middleware('throttle:message-reads')
    ->name('threads.show');
Route::post('messages/{message}/thread/read', [ThreadReadController::class, 'store'])
    ->middleware('throttle:message-reads')
    ->name('threads.read.store');
Route::post('messages/{message}/thread/subscription', [ThreadSubscriptionController::class, 'store'])
    ->name('threads.subscription.store');
Route::delete('messages/{message}/thread/subscription', [ThreadSubscriptionController::class, 'destroy'])
    ->name('threads.subscription.destroy');

Route::post('messages/{message}/pin', [PinController::class, 'store'])->name('pins.store');
Route::delete('messages/{message}/pin', [PinController::class, 'destroy'])->name('pins.destroy');

Route::post('messages/{message}/save', [SaveController::class, 'store'])->name('saves.store');
Route::delete('messages/{message}/save', [SaveController::class, 'destroy'])->name('saves.destroy');

Route::post('messages/{message}/reactions', [ReactionController::class, 'store'])
    ->middleware('throttle:messages')
    ->name('reactions.store');
Route::delete('messages/{message}/reactions', [ReactionController::class, 'destroy'])
    ->middleware('throttle:messages')
    ->name('reactions.destroy');

// Approving or rejecting Bite's pending action card.
Route::post('messages/{message}/bite', [BiteApprovalController::class, 'store'])
    ->middleware('throttle:messages')
    ->name('bite.approvals.store');

/*
 * The composer's attachment tray: upload a file, get an id, send it with the
 * message as `attachment_ids[]`. Discarding works only while still unattached.
 */
Route::post('uploads', [UploadController::class, 'store'])
    ->middleware('throttle:uploads')
    ->name('uploads.store');
Route::delete('uploads/{attachment}', [UploadController::class, 'destroy'])
    ->name('uploads.destroy');
