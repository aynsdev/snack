<?php

namespace App\Mcp\Tools\Concerns;

use App\Ai\BiteContext;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Turning the names an assistant uses — "ENG", "the mobile project", "ENG-42",
 * "@ada" — into records the person is allowed to see.
 *
 * Every lookup ends in a policy check against the token's owner, and every
 * miss is the same null whether the record does not exist or is merely out of
 * reach. That is the rule the screens follow (ProjectPolicy answers a guest
 * with a 404, not a 403), and an assistant must not be a way around it.
 */
trait ResolvesProjectWork
{
    /**
     * A project by key or name: exact first, then a partial name only when it
     * is unambiguous — guessing between two similar boards is how work gets
     * filed in the wrong place.
     */
    protected function project(BiteContext $context, mixed $named): ?Project
    {
        $name = is_string($named) ? trim($named) : '';

        if ($name === '' || $context->isGuest()) {
            return null;
        }

        $projects = fn (): Builder => Project::query()->where('workspace_id', $context->workspace->id);

        $project = $projects()
            ->where(fn (Builder $query) => $query->where('key', 'ilike', $name)->orWhere('name', 'ilike', $name))
            ->orderBy('is_archived')
            ->first();

        if ($project === null) {
            $partial = $projects()->notArchived()->where('name', 'ilike', '%'.$name.'%')->limit(2)->get();
            $project = $partial->count() === 1 ? $partial->first() : null;
        }

        return $project !== null && Gate::forUser($context->user)->allows('view', $project) ? $project : null;
    }

    /**
     * A task by its reference, `ENG-42` — the identifier people actually say,
     * and the one every tool prints.
     */
    protected function task(BiteContext $context, mixed $reference): ?Task
    {
        $reference = is_string($reference) ? strtoupper(trim($reference)) : '';

        if (! preg_match('/^([A-Z][A-Z0-9]*)-(\d+)$/', $reference, $parts)) {
            return null;
        }

        $project = $this->project($context, $parts[1]);

        if ($project === null || strtoupper($project->key) !== $parts[1]) {
            return null;
        }

        $task = $project->tasks()->where('number', (int) $parts[2])->first();

        if ($task === null) {
            return null;
        }

        $task->setRelation('project', $project);

        return Gate::forUser($context->user)->allows('view', $task) ? $task : null;
    }

    /**
     * A member of the project by @username or exact name.
     *
     * Username first, and both exact: "Chris" must not assign work to
     * whichever Christopher the database returns first.
     */
    protected function projectMember(Project $project, mixed $named): ?User
    {
        $name = is_string($named) ? trim(ltrim(trim($named), '@')) : '';

        if ($name === '') {
            return null;
        }

        return $project->users()->where('users.username', $name)->first()
            ?? $project->users()->where('users.name', 'ilike', $name)->first();
    }

    /**
     * What to say when a project could not be resolved, with the options, so
     * the assistant can ask which one was meant rather than guess.
     */
    protected function noSuchProject(BiteContext $context, mixed $named): string
    {
        if ($context->isGuest()) {
            return 'This person is a guest and cannot see projects.';
        }

        $keys = Project::query()
            ->where('workspace_id', $context->workspace->id)
            ->notArchived()
            ->orderBy('name')
            ->limit(20)
            ->get(['key', 'name'])
            ->map(fn (Project $project): string => "{$project->key} ({$project->name})")
            ->implode(', ');

        $name = is_string($named) ? $named : '';

        return $keys === ''
            ? 'There are no projects in this workspace.'
            : "No project matches [{$name}]. The projects are: {$keys}.";
    }
}
