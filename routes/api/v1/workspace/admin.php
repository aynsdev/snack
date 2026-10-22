<?php

use App\Http\Controllers\Api\V1\Workspaces\AuditLogController;
use App\Http\Controllers\Api\V1\Workspaces\BiteController;
use App\Http\Controllers\Api\V1\Workspaces\BiteMemoryController;
use App\Http\Controllers\Api\V1\Workspaces\ConnectorController;
use App\Http\Controllers\Api\V1\Workspaces\IncomingWebhookController;
use App\Http\Controllers\Api\V1\Workspaces\InvitationController;
use App\Http\Controllers\Api\V1\Workspaces\InviteLinkController;
use App\Http\Controllers\Api\V1\Workspaces\LogoController;
use App\Http\Controllers\Api\V1\Workspaces\MemberController;
use App\Http\Controllers\Api\V1\Workspaces\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workspace administration
|--------------------------------------------------------------------------
|
| Settings, logo, members, invitations, the audit log, Bite's configuration,
| AI connectors and incoming webhooks — the web app's admin screens, with the
| same gates (policies and form requests) and the same throttles. Connectors
| are the exception that is per person rather than admin-gated.
|
*/

Route::get('settings', [SettingsController::class, 'edit'])->name('workspace-settings.edit');
Route::patch('settings', [SettingsController::class, 'update'])->name('workspace-settings.update');

Route::post('settings/logo', [LogoController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('workspace-logo.store');
Route::delete('settings/logo', [LogoController::class, 'destroy'])->name('workspace-logo.destroy');

Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');

Route::get('members', [MemberController::class, 'index'])->name('members.index');
Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

Route::get('invitations', [InvitationController::class, 'index'])->name('workspace-invitations.index');
Route::post('invitations', [InvitationController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('workspace-invitations.store');
Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])
    ->name('workspace-invitations.destroy');

// A singleton: there is only ever one live link per workspace.
Route::post('invite-link', [InviteLinkController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('workspace-invite-link.store');
Route::delete('invite-link', [InviteLinkController::class, 'destroy'])->name('workspace-invite-link.destroy');

Route::get('bite', [BiteController::class, 'edit'])->name('bite-settings.edit');
Route::patch('bite', [BiteController::class, 'update'])->name('bite-settings.update');

Route::post('bite/memories', [BiteMemoryController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('bite-memories.store');
Route::patch('bite/memories/{biteMemory}', [BiteMemoryController::class, 'update'])->name('bite-memories.update');
Route::delete('bite/memories/{biteMemory}', [BiteMemoryController::class, 'destroy'])->name('bite-memories.destroy');

Route::get('connectors', [ConnectorController::class, 'index'])->name('connectors.index');
Route::post('connectors', [ConnectorController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('connectors.store');
Route::delete('connectors/tokens/{token}', [ConnectorController::class, 'destroyToken'])
    ->where('token', '[A-Za-z0-9]{1,80}')
    ->name('connectors.tokens.destroy');
Route::delete('connectors/apps/{grant}', [ConnectorController::class, 'destroyApp'])
    ->whereNumber('grant')
    ->name('connectors.apps.destroy');

Route::get('webhooks', [IncomingWebhookController::class, 'index'])->name('webhooks.index');
Route::post('webhooks', [IncomingWebhookController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('webhooks.store');
Route::patch('webhooks/{webhook}', [IncomingWebhookController::class, 'update'])->name('webhooks.update');
Route::patch('webhooks/{webhook}/revoke', [IncomingWebhookController::class, 'revoke'])->name('webhooks.revoke');
Route::patch('webhooks/{webhook}/restore', [IncomingWebhookController::class, 'restore'])->name('webhooks.restore');
Route::patch('webhooks/{webhook}/signing', [IncomingWebhookController::class, 'updateSigning'])->name('webhooks.signing');
Route::delete('webhooks/{webhook}', [IncomingWebhookController::class, 'destroy'])->name('webhooks.destroy');
