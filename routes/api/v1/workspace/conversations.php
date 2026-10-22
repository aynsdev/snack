<?php

use App\Http\Controllers\Api\V1\Conversations\ConversationController;
use App\Http\Controllers\Api\V1\Conversations\ConversationPreferenceController;
use App\Http\Controllers\Api\V1\Conversations\ParticipantController;
use App\Http\Controllers\Conversations\ConversationReadController;
use App\Http\Controllers\Conversations\DirectoryController;
use App\Http\Controllers\Conversations\HuddleController;
use App\Http\Controllers\Conversations\MessageController;
use Illuminate\Support\Facades\Route;

/*
 * Direct and group conversations, bound by their opaque public id. Bite chats
 * are conversations too: their messages, reads and huddle-less timeline use
 * these same routes.
 *
 * The directory, history, posting, read pointer and huddle endpoints already
 * answer with JSON on the web, so they are routed straight to those controllers.
 */
Route::get('dms/directory', [DirectoryController::class, 'index'])
    ->middleware('throttle:message-reads')
    ->name('conversations.directory.index');

Route::get('dms', [ConversationController::class, 'index'])->name('conversations.index');
Route::post('dms', [ConversationController::class, 'store'])
    ->middleware('throttle:messages')
    ->name('conversations.store');
Route::get('dms/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
Route::patch('dms/{conversation}', [ConversationController::class, 'update'])->name('conversations.update');

Route::get('dms/{conversation}/messages', [MessageController::class, 'index'])
    ->middleware('throttle:message-reads')
    ->name('conversations.messages.index');
Route::post('dms/{conversation}/messages', [MessageController::class, 'store'])
    ->middleware('throttle:messages')
    ->name('conversations.messages.store');

Route::post('dms/{conversation}/read', [ConversationReadController::class, 'store'])
    ->middleware('throttle:message-reads')
    ->name('conversations.read.store');

Route::post('dms/{conversation}/huddle', [HuddleController::class, 'store'])
    ->middleware('throttle:huddles')
    ->name('conversations.huddle.store');
Route::delete('dms/{conversation}/huddle', [HuddleController::class, 'destroy'])
    ->name('conversations.huddle.destroy');

Route::post('dms/{conversation}/participants', [ParticipantController::class, 'store'])
    ->name('conversations.participants.store');
Route::delete('dms/{conversation}/participants', [ParticipantController::class, 'destroy'])
    ->name('conversations.participants.destroy');

Route::patch('dms/{conversation}/preferences', [ConversationPreferenceController::class, 'update'])
    ->name('conversations.preferences.update');
