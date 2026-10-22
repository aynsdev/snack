<?php

use App\Http\Controllers\Api\V1\Workspaces\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
