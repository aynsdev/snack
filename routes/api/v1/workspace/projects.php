<?php

use App\Http\Controllers\Api\V1\Projects\BoardColumnController;
use App\Http\Controllers\Api\V1\Projects\BoardController;
use App\Http\Controllers\Api\V1\Projects\ProjectController;
use App\Http\Controllers\Api\V1\Projects\ProjectGroupController;
use App\Http\Controllers\Api\V1\Projects\ProjectMemberController;
use App\Http\Controllers\Api\V1\Projects\ProjectMembershipController;
use App\Http\Controllers\Api\V1\Projects\ProjectStarController;
use App\Http\Controllers\Api\V1\Projects\ProjectSummaryController;
use App\Http\Controllers\Api\V1\Projects\ReleaseController;
use App\Http\Controllers\Api\V1\Projects\SprintController;
use App\Http\Controllers\Api\V1\Projects\TaskLabelController;
use App\Http\Controllers\Projects\TaskAttachmentController;
use App\Http\Controllers\Projects\TaskCommentController;
use App\Http\Controllers\Projects\TaskController;
use App\Http\Controllers\Projects\TaskLinkController;
use App\Http\Controllers\Projects\TaskMoveController;
use App\Http\Controllers\Projects\TaskPickerController;
use App\Http\Controllers\Projects\TaskRelationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Projects, boards and tasks
|--------------------------------------------------------------------------
|
| The same tree as the web app's project routes — see routes/web.php for why
| each one is shaped the way it is. The pages and the redirecting forms have
| API counterparts in Api\V1\Projects; the card endpoints already answer with
| JSON, so they are routed straight to the web controllers.
|
*/

Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

Route::post('project-groups', [ProjectGroupController::class, 'store'])->name('project-groups.store');
Route::patch('project-groups/{projectGroup}', [ProjectGroupController::class, 'update'])->name('project-groups.update');
Route::post('project-groups/{projectGroup}/move', [ProjectGroupController::class, 'move'])->name('project-groups.move');
Route::delete('project-groups/{projectGroup}', [ProjectGroupController::class, 'destroy'])->name('project-groups.destroy');

Route::get('projects/{project}', [BoardController::class, 'show'])->name('projects.board');
Route::get('projects/{project}/backlog', [BoardController::class, 'backlog'])->name('projects.backlog');
Route::get('projects/{project}/summary', [ProjectSummaryController::class, 'apiShow'])->name('projects.summary');

Route::get('projects/{project}/settings', [ProjectController::class, 'settings'])->name('projects.settings.show');
Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
Route::patch('projects/{project}/group', [ProjectController::class, 'assignGroup'])->name('projects.group.update');

Route::post('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive.store');
Route::delete('projects/{project}/archive', [ProjectController::class, 'unarchive'])->name('projects.archive.destroy');

Route::post('projects/{project}/star', [ProjectStarController::class, 'store'])->name('projects.star.store');
Route::delete('projects/{project}/star', [ProjectStarController::class, 'destroy'])->name('projects.star.destroy');

Route::post('projects/{project}/membership', [ProjectMembershipController::class, 'store'])->name('projects.membership.store');
Route::delete('projects/{project}/membership', [ProjectMembershipController::class, 'destroy'])->name('projects.membership.destroy');

Route::post('projects/{project}/members', [ProjectMemberController::class, 'store'])->name('projects.members.store');
Route::patch('projects/{project}/members/{member}', [ProjectMemberController::class, 'update'])->name('projects.members.update');
Route::delete('projects/{project}/members/{member}', [ProjectMemberController::class, 'destroy'])->name('projects.members.destroy');

Route::post('projects/{project}/columns', [BoardColumnController::class, 'store'])->name('projects.columns.store');
Route::patch('projects/{project}/columns/{column}', [BoardColumnController::class, 'update'])->name('projects.columns.update');
Route::delete('projects/{project}/columns/{column}', [BoardColumnController::class, 'destroy'])->name('projects.columns.destroy');

Route::post('projects/{project}/labels', [TaskLabelController::class, 'store'])->name('projects.labels.store');
Route::patch('projects/{project}/labels/{label}', [TaskLabelController::class, 'update'])->name('projects.labels.update');
Route::delete('projects/{project}/labels/{label}', [TaskLabelController::class, 'destroy'])->name('projects.labels.destroy');

Route::post('projects/{project}/sprints', [SprintController::class, 'store'])->name('projects.sprints.store');
Route::patch('projects/{project}/sprints/{sprint}', [SprintController::class, 'update'])->name('projects.sprints.update');
Route::delete('projects/{project}/sprints/{sprint}', [SprintController::class, 'destroy'])->name('projects.sprints.destroy');
Route::post('projects/{project}/sprints/{sprint}/start', [SprintController::class, 'start'])->name('projects.sprints.start');
Route::post('projects/{project}/sprints/{sprint}/complete', [SprintController::class, 'complete'])->name('projects.sprints.complete');

Route::get('projects/{project}/releases', [ReleaseController::class, 'index'])->name('projects.releases.index');
Route::post('projects/{project}/releases', [ReleaseController::class, 'store'])->name('projects.releases.store');
Route::get('projects/{project}/releases/{release}', [ReleaseController::class, 'show'])->name('projects.releases.show');
Route::patch('projects/{project}/releases/{release}', [ReleaseController::class, 'update'])->name('projects.releases.update');
Route::delete('projects/{project}/releases/{release}', [ReleaseController::class, 'destroy'])->name('projects.releases.destroy');
Route::post('projects/{project}/releases/{release}/released', [ReleaseController::class, 'release'])->name('projects.releases.released.store');
Route::delete('projects/{project}/releases/{release}/released', [ReleaseController::class, 'unrelease'])->name('projects.releases.released.destroy');

/*
 * Cards. These web endpoints already speak JSON (`{"task": ...}`), so the API
 * shares them outright, throttles included.
 */
Route::post('projects/{project}/tasks', [TaskController::class, 'store'])
    ->middleware('throttle:messages')
    ->name('projects.tasks.store');

Route::get('tasks/{task}', [TaskController::class, 'show'])
    ->middleware('throttle:message-reads')
    ->name('tasks.show');
Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

Route::patch('tasks/{task}/position', [TaskMoveController::class, 'update'])
    ->middleware('throttle:messages')
    ->name('tasks.move');

Route::get('tasks/{task}/picker', [TaskPickerController::class, 'index'])
    ->middleware('throttle:message-reads')
    ->name('tasks.picker');

Route::post('tasks/{task}/comments', [TaskCommentController::class, 'store'])
    ->middleware('throttle:messages')
    ->name('tasks.comments.store');
Route::patch('tasks/{task}/comments/{comment}', [TaskCommentController::class, 'update'])->name('tasks.comments.update');
Route::delete('tasks/{task}/comments/{comment}', [TaskCommentController::class, 'destroy'])->name('tasks.comments.destroy');

Route::post('tasks/{task}/links', [TaskLinkController::class, 'store'])
    ->middleware('throttle:messages')
    ->name('tasks.links.store');
Route::delete('tasks/{task}/links/{link}', [TaskLinkController::class, 'destroy'])->name('tasks.links.destroy');

Route::post('tasks/{task}/relations', [TaskRelationController::class, 'store'])
    ->middleware('throttle:messages')
    ->name('tasks.relations.store');

// Unscoped, as on the web: a relation shows on both cards, and the controller
// checks the workspace and the card itself.
Route::delete('tasks/{task}/relations/{relation}', [TaskRelationController::class, 'destroy'])
    ->withoutScopedBindings()
    ->name('tasks.relations.destroy');

Route::post('tasks/{task}/attachments', [TaskAttachmentController::class, 'store'])
    ->middleware('throttle:uploads')
    ->name('tasks.attachments.store');
Route::delete('tasks/{task}/attachments/{attachment}', [TaskAttachmentController::class, 'destroy'])->name('tasks.attachments.destroy');
