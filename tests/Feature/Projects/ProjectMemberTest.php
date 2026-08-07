<?php

use App\Enums\ColumnCategory;
use App\Enums\MemberStatus;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\ProjectGroup;
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

it('explains the gap between a group’s count and the sidebar, and offers the way across', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $group = ProjectGroup::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Development',
    ]);

    $mine = makeProject($workspace, $owner, ['name' => 'Pedi Client App']);
    $theirs = makeProject($workspace, joinWorkspace($workspace), ['name' => 'Pedi Eticket Web App']);

    foreach ([$mine, $theirs] as $project) {
        $project->update(['project_group_id' => $group->id]);
    }

    /*
     * The list page counts every project in the group; the sidebar counts the
     * ones this person is on. Both are right, and the difference is membership
     * — which is exactly what somebody seeing "2" beside a one-row sidebar
     * needs the page to tell them.
     */
    $this->actingAs($owner)
        ->get(route('projects.index', $workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('projects', 2)
            ->where('projects.0.name', 'Pedi Client App')
            ->where('projects.0.is_member', true)
            ->where('projects.0.abilities.join', false)
            ->where('projects.0.abilities.leave', true)
            ->where('projects.1.name', 'Pedi Eticket Web App')
            ->where('projects.1.is_member', false)
            // The control the card draws, and the only route into a project
            // somebody is not already on.
            ->where('projects.1.abilities.join', true)
            ->where('projects.1.abilities.leave', false)
        );

    $this->actingAs($owner)
        ->post(route('projects.membership.store', [$workspace->slug, $theirs->slug]))
        ->assertRedirect();

    // And then it is in the sidebar, which is the thing that was missing.
    $this->actingAs($owner)
        ->get(route('projects.index', $workspace->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('snack.current.projects', 2)
            ->where('snack.current.projects.1.name', 'Pedi Eticket Web App')
        );
});

it('offers a guest no way onto a project they were not added to', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    // A guest cannot even see it, so "join" is not a door that is merely shut.
    expect($guest->can('join', $project))->toBeFalse();

    $this->actingAs($guest)
        ->post(route('projects.membership.store', [$workspace->slug, $project->slug]))
        ->assertNotFound();
});
