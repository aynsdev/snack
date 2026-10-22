<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Projects\AddProjectMembers;
use App\Actions\Projects\RemoveProjectMember;
use App\Enums\ProjectRole;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Workspace;
use App\Services\ProjectBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adding other people to a project, changing their role, and removing them —
 * see {@see \App\Http\Controllers\Projects\ProjectMemberController}.
 *
 * Keyed by the membership row, so scoped bindings tie it to the project in the
 * path. Answers in the shape {@see ProjectBoard::members()} lists people in.
 */
class ProjectMemberController extends Controller
{
    public function __construct(private readonly ProjectBoard $boards) {}

    /**
     * Adds people, and answers with the whole roster: the client asked to add
     * several at once, and some may already have been on it.
     */
    public function store(
        Workspace $workspace,
        Project $project,
        Request $request,
        AddProjectMembers $addMembers,
    ): JsonResponse {
        $this->authorize('manageMembers', $project);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['integer'],
            'role' => ['nullable', Rule::enum(ProjectRole::class)],
        ]);

        $added = $addMembers->handle(
            project: $project,
            actor: $request->user(),
            userIds: $validated['user_ids'],
            role: ProjectRole::tryFrom($validated['role'] ?? '') ?? ProjectRole::Member,
        );

        return response()->json([
            'data' => $this->boards->members($project),
            'meta' => ['added' => $added->count()],
        ], 201);
    }

    /**
     * Changes somebody's role. The last lead cannot be demoted.
     */
    public function update(
        Workspace $workspace,
        Project $project,
        ProjectMember $member,
        Request $request,
    ): JsonResponse {
        $this->authorize('manageMembers', $project);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(ProjectRole::class)],
        ]);

        $role = ProjectRole::from($validated['role']);

        if ($role !== ProjectRole::Lead && $this->isLastLead($project, $member)) {
            throw ValidationException::withMessages(['role' => 'A project needs at least one lead.']);
        }

        $member->update(['role' => $role]);

        $payload = collect($this->boards->members($project))->firstWhere('id', $member->id);

        return response()->json(['data' => $payload]);
    }

    /**
     * Takes somebody off the project, unassigning their open tasks.
     */
    public function destroy(
        Workspace $workspace,
        Project $project,
        ProjectMember $member,
        Request $request,
        RemoveProjectMember $removeMember,
    ): Response {
        $this->authorize('manageMembers', $project);

        if ($this->isLastLead($project, $member)) {
            throw ValidationException::withMessages(['member' => 'A project needs at least one lead.']);
        }

        $removeMember->handle($project, $request->user(), $member->user);

        return response()->noContent();
    }

    private function isLastLead(Project $project, ProjectMember $member): bool
    {
        if (! $member->isLead()) {
            return false;
        }

        return $project->members()
            ->where('role', ProjectRole::Lead->value)
            ->whereKeyNot($member->id)
            ->doesntExist();
    }
}
