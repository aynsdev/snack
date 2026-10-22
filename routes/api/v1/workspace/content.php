<?php

use App\Http\Controllers\Api\V1\Assets\AssetController;
use App\Http\Controllers\Api\V1\Assets\AssetFolderController;
use App\Http\Controllers\Api\V1\Assets\AssetLibraryController;
use App\Http\Controllers\Api\V1\Canvases\CanvasController;
use App\Http\Controllers\Api\V1\Canvases\CanvasCoverController;
use App\Http\Controllers\Api\V1\Canvases\CanvasStarController;
use App\Http\Controllers\Api\V1\Files\FileBrowserController;
use App\Http\Controllers\Api\V1\Files\FileStarController;
use Illuminate\Support\Facades\Route;

/*
 * Files, canvases and the asset library — the web app's "content" sections.
 * Downloads themselves live in routes/api/v1/files.php, outside the workspace
 * prefix, exactly as the web app's do.
 */

Route::get('files', [FileBrowserController::class, 'index'])
    ->middleware('throttle:message-reads')
    ->name('files.index');
Route::post('files/{attachment}/star', [FileStarController::class, 'store'])->name('files.star.store');
Route::delete('files/{attachment}/star', [FileStarController::class, 'destroy'])->name('files.star.destroy');

Route::get('canvases', [CanvasController::class, 'index'])->name('canvases.index');
Route::post('canvases', [CanvasController::class, 'store'])->name('canvases.store');
Route::post('channels/{channel}/canvases', [CanvasController::class, 'storeForChannel'])->name('channels.canvases.store');
Route::post('dms/{conversation}/canvases', [CanvasController::class, 'storeForConversation'])->name('conversations.canvases.store');
Route::get('canvases/{canvas}', [CanvasController::class, 'show'])->name('canvases.show');
Route::patch('canvases/{canvas}', [CanvasController::class, 'update'])->name('canvases.update');
Route::delete('canvases/{canvas}', [CanvasController::class, 'destroy'])->name('canvases.destroy');
Route::post('canvases/{canvas}/star', [CanvasStarController::class, 'store'])->name('canvases.star.store');
Route::delete('canvases/{canvas}/star', [CanvasStarController::class, 'destroy'])->name('canvases.star.destroy');
Route::post('canvases/{canvas}/cover', [CanvasCoverController::class, 'store'])->name('canvases.cover.store');
Route::delete('canvases/{canvas}/cover', [CanvasCoverController::class, 'destroy'])->name('canvases.cover.destroy');

/*
 * {assetFolder} rather than {folder} so scopeBindings() resolves it through
 * Workspace::assetFolders(), as on the web.
 */
Route::get('assets', [AssetLibraryController::class, 'index'])->name('assets.index');
Route::get('assets/folders/{assetFolder}', [AssetLibraryController::class, 'show'])->name('assets.folders.show');
Route::post('assets/folders', [AssetFolderController::class, 'store'])->name('assets.folders.store');
Route::patch('assets/folders/{assetFolder}', [AssetFolderController::class, 'update'])->name('assets.folders.update');
Route::delete('assets/folders/{assetFolder}', [AssetFolderController::class, 'destroy'])->name('assets.folders.destroy');
Route::post('assets', [AssetController::class, 'store'])
    ->middleware('throttle:uploads')
    ->name('assets.store');
Route::patch('assets/{asset}', [AssetController::class, 'update'])->name('assets.update');
Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->name('assets.destroy');
