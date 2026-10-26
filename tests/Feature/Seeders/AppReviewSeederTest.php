<?php

use App\Enums\WorkspaceRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Database\Seeders\AppReviewSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config([
        'snack.mobile.app_review.email' => 'appreview@pedi.ph',
        'snack.mobile.app_review.username' => 'appreview',
        'snack.mobile.app_review.password' => 'review-secret',
        'snack.mobile.app_review.workspace' => 'snack-demo',
    ]);
});

function reviewer(): User
{
    return User::query()->where('email', 'appreview@pedi.ph')->sole();
}

function demoWorkspace(): Workspace
{
    return Workspace::query()->where('slug', 'snack-demo')->sole();
}

test('it builds a demo workspace the reviewer is a member of', function () {
    $this->seed(AppReviewSeeder::class);

    $reviewer = reviewer();
    $workspace = demoWorkspace();

    expect($reviewer->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('review-secret', $reviewer->password))->toBeTrue()
        ->and($workspace->owner_id)->not->toBe($reviewer->id)
        ->and($reviewer->roleIn($workspace))->toBe(WorkspaceRole::Member)
        ->and(WorkspaceMember::query()->where('user_id', $reviewer->id)->count())->toBe(1)
        ->and(Employee::query()->where('workspace_id', $workspace->id)->where('user_id', $reviewer->id)->exists())->toBeTrue()
        ->and($workspace->channels()->pluck('name')->sort()->values()->all())->toBe(['announcements', 'design', 'general', 'launch'])
        ->and(Task::query()->where('assignee_id', $reviewer->id)->count())->toBe(1);

    expect($workspace->channels()->where('name', 'general')->sole()->messages()->pluck('body'))
        ->toContain('Say hi to our newest teammate, @appreview!');
});

test('the reviewer sees the demo content on the web and in the app', function () {
    $this->seed(AppReviewSeeder::class);

    $this->actingAs(reviewer())->followingRedirects()->get(route('workspaces.show', demoWorkspace()))->assertOk();

    app('auth')->forgetGuards();

    $this->withToken(mobileToken(reviewer()))
        ->getJson(route('api.v1.workspaces.show', demoWorkspace()))
        ->assertOk()
        ->assertJsonCount(4, 'data.channels')
        ->assertJsonCount(1, 'data.conversations');
});

test('running it again resets the workspace and the password', function () {
    $this->seed(AppReviewSeeder::class);

    $first = demoWorkspace();
    $reviewer = reviewer();

    // What a reviewer might leave behind, including a leave request, whose
    // type is restricted rather than cascaded.
    $first->channels()->where('name', 'general')->sole()->messages()->create([
        'workspace_id' => $first->id,
        'sender_id' => $reviewer->id,
        'body' => 'testing 123',
        'body_plaintext' => 'testing 123',
        'metadata' => [],
    ]);
    LeaveRequest::factory()->create([
        'workspace_id' => $first->id,
        'employee_id' => Employee::query()->where('workspace_id', $first->id)->where('user_id', $reviewer->id)->sole()->id,
        'leave_type_id' => LeaveType::factory()->create(['workspace_id' => $first->id])->id,
    ]);

    config(['snack.mobile.app_review.password' => 'rotated-secret']);
    $this->seed(AppReviewSeeder::class);

    expect(Workspace::query()->whereKey($first->id)->exists())->toBeFalse()
        ->and(Workspace::query()->where('slug', 'snack-demo')->count())->toBe(1)
        ->and(User::query()->where('email', 'appreview@pedi.ph')->count())->toBe(1)
        ->and(Hash::check('rotated-secret', reviewer()->password))->toBeTrue()
        ->and(demoWorkspace()->channels()->where('name', 'general')->sole()->messages()->pluck('body'))->not->toContain('testing 123');
});

test('it recreates the reviewer after they delete their account', function () {
    $this->seed(AppReviewSeeder::class);

    reviewer()->delete();

    $this->seed(AppReviewSeeder::class);

    expect(reviewer()->roleIn(demoWorkspace()))->toBe(WorkspaceRole::Member);
});

test('it refuses to run without a password', function () {
    config(['snack.mobile.app_review.password' => null]);

    expect(fn () => $this->seed(AppReviewSeeder::class))->toThrow(RuntimeException::class, 'SNACK_APP_REVIEW_PASSWORD');

    expect(User::query()->where('email', 'appreview@pedi.ph')->exists())->toBeFalse();
});

test('it never replaces a real workspace that shares the slug', function () {
    $real = Workspace::factory()->create(['slug' => 'snack-demo']);

    expect(fn () => $this->seed(AppReviewSeeder::class))->toThrow(RuntimeException::class, 'not the demo workspace');

    expect(Workspace::query()->whereKey($real->id)->exists())->toBeTrue();
});
