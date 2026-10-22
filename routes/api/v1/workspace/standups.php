<?php

use App\Http\Controllers\Api\V1\Standups\StandupController;
use App\Http\Controllers\Standups\StandupResponseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Standups
|--------------------------------------------------------------------------
|
| Configuring them is admin work; answering one is the asked person's, on the
| web's own JSON endpoint. That one is outside scoped bindings because a
| response is three hops from the workspace — ownership, via
| StandupPolicy::respond(), is what protects it.
|
*/

Route::get('standups', [StandupController::class, 'index'])->name('standups.index');
Route::post('standups', [StandupController::class, 'store'])->name('standups.store');
Route::patch('standups/{standup}', [StandupController::class, 'update'])->name('standups.update');
Route::delete('standups/{standup}', [StandupController::class, 'destroy'])->name('standups.destroy');

Route::post('standup-responses/{response}', [StandupResponseController::class, 'store'])
    ->middleware('throttle:messages')
    ->withoutScopedBindings()
    ->name('standup-responses.store');
