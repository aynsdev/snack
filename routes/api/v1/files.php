<?php

use App\Http\Controllers\Files\DownloadController;
use Illuminate\Support\Facades\Route;

/*
 * Downloading attachments. Outside the workspace prefix like the web app's
 * `files/{attachment}` — the attachment knows its own workspace, and the
 * policy checks it.
 *
 * The web controller is reused as it is: it streams, and it authorizes every
 * hit through the container policy rather than through the session. What is
 * dropped is `signed:relative` — the bearer token is the credential here, so
 * the app can take any `download_url` / `thumbnail_url` / `preview_url` /
 * `stream_url` a resource hands it, put `/api/v1` in front of the path, and
 * fetch it with its token. The signature parameters still on the query are
 * simply ignored; `inline` and Bite's `share` are honoured.
 */
Route::get('files/{attachment}', [DownloadController::class, 'show'])->name('files.show');
Route::get('files/{attachment}/thumbnail', [DownloadController::class, 'thumbnail'])->name('files.thumbnail');
Route::get('files/{attachment}/play', [DownloadController::class, 'play'])->name('files.play');
