<?php

use App\Http\Controllers\Api\V1\Workspaces\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspaceController::class, 'show'])->name('workspaces.show');
