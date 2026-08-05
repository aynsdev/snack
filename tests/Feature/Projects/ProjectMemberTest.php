<?php

use App\Enums\ColumnCategory;
use App\Enums\MemberStatus;
use App\Enums\ProjectRole;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;

it('adds only active members of this workspace', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $colleague = joinWorkspace($workspace);
    $suspended = joinWorkspace($workspace, status: MemberStatus::Suspended);
    $stranger = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('projects.members.store', [$workspace->slug, $project->slug]), [
            'user_ids' => [$colleague->id, $suspended->id, $stranger->id],
        ])
        ->assertRedirect();

    // Every id came from a picker in a browser, so every one is re-checked: an
    // assignee list is what this table becomes.
    expect($project->members()->pluck('user_id')->all())
        ->toEqualCanonicalizing([$owner->id, $colleague->id]);
});

it('is idempotent about somebody already on the project', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $colleague = joinWorkspace($workspace);

    addToProject($project, $colleague);

    $this->actingAs($owner)
        ->post(route('projects.members.store', [$workspace->slug, $project->slug]), [
            'user_ids' => [$colleague->id],
        ])
        ->assertRedirect();

    expect(ProjectMember::where('project_id', $project->id)->count())->toBe(2);
});

it('hands back the open tasks of somebody removed, and keeps the finished ones', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $leaver = joinWorkspace($workspace);
    $membership = addToProject($project, $leaver);

    $open = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Started)->id,
        'assignee_id' => $leaver->id,
    ]);

    $finished = Task::factory()->create([
        'project_id' => $project->id,
        'board_column_id' => projectColumn($project, ColumnCategory::Done)->id,
        'assignee_id' => $leaver->id,
        'completed_at' => now(),
    ]);

    $this->actingAs($owner)
        ->delete(route('projects.members.destroy', [$workspace->slug, $project->slug, $membership->id]))
        ->assertRedirect();

    // A card still showing their face is a card the team keeps waiting on.
    expect($open->refresh()->assignee_id)->toBeNull()
        // Completed work is a record of who did it, and that stays true.
        ->and($finished->refresh()->assignee_id)->toBe($leaver->id);
});

it('will not leave a project without a lead', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);

    $membership = $project->membershipFor($owner);

    $this->actingAs($owner)
        ->patch(route('projects.members.update', [$workspace->slug, $project->slug, $membership->id]), [
            'role' => 'member',
        ])
        ->assertSessionHas('error');

    expect($membership->refresh()->role)->toBe(ProjectRole::Lead);

    $this->actingAs($owner)
        ->delete(route('projects.members.destroy', [$workspace->slug, $project->slug, $membership->id]))
        ->assertSessionHas('error');

    expect($project->members()->count())->toBe(1);
});

it('lets anyone who can see a board put themselves on it', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $colleague = joinWorkspace($workspace);

    $this->actingAs($colleague)
        ->post(route('projects.membership.store', [$workspace->slug, $project->slug]))
        ->assertRedirect();

    expect($project->fresh()->hasMember($colleague))->toBeTrue();

    // And leaving takes their open work back off their name.
    $this->actingAs($colleague)
        ->delete(route('projects.membership.destroy', [$workspace->slug, $project->slug]))
        ->assertRedirect(route('projects.index', $workspace->slug));

    expect($project->fresh()->hasMember($colleague))->toBeFalse();
});

it('refuses a member the right to add other people', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $member = joinWorkspace($workspace);
    addToProject($project, $member);

    $this->actingAs($member)
        ->post(route('projects.members.store', [$workspace->slug, $project->slug]), [
            'user_ids' => [joinWorkspace($workspace)->id],
        ])
        ->assertForbidden();
});
