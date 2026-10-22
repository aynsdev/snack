<?php

use App\Http\Controllers\Api\V1\Activity\ActivityController;
use App\Http\Controllers\Api\V1\Huddles\HuddleHistoryController;
use App\Http\Controllers\Api\V1\Search\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Activity, search and huddle history
|--------------------------------------------------------------------------
|
| The read-only views across the whole workspace: what needs you, what was
| said, and which calls happened.
|
*/

Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

Route::get('search', [SearchController::class, 'index'])
    ->middleware('throttle:message-reads')
    ->name('search.index');

Route::get('huddles', [HuddleHistoryController::class, 'index'])->name('huddles.index');
