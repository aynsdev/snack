<?php

use App\Http\Controllers\Api\V1\Bite\BiteChatController;
use App\Http\Controllers\Api\V1\Bite\BiteMediaController;
use App\Http\Controllers\Api\V1\Bite\BiteShareController;
use App\Http\Controllers\Bite\BiteShareController as WebBiteShareController;
use Illuminate\Support\Facades\Route;

/*
 * A person's own chats with Bite. Under `ai/` as on the web, because `bite` is
 * the admin's settings. Each chat is a conversation of type `bite`: sending,
 * history and reads go through `dms/{conversation}/...`.
 */
Route::get('ai', [BiteChatController::class, 'index'])->name('bite.index');
Route::get('ai/media', [BiteMediaController::class, 'index'])->name('bite.media.index');
Route::get('ai/shared/{token}', [BiteShareController::class, 'show'])->name('bite.shared.show');

Route::post('ai/chats', [BiteChatController::class, 'store'])
    ->middleware('throttle:messages')
    ->name('bite.chats.store');
Route::get('ai/chats/{conversation}', [BiteChatController::class, 'show'])->name('bite.chats.show');
Route::patch('ai/chats/{conversation}', [BiteChatController::class, 'update'])->name('bite.chats.update');
Route::delete('ai/chats/{conversation}', [BiteChatController::class, 'destroy'])->name('bite.chats.destroy');

// Sharing already answers with JSON on the web.
Route::post('ai/chats/{conversation}/share', [WebBiteShareController::class, 'store'])->name('bite.chats.share.store');
Route::delete('ai/chats/{conversation}/share', [WebBiteShareController::class, 'destroy'])->name('bite.chats.share.destroy');
