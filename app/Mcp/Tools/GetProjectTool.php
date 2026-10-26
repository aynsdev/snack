<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Mcp\Tools\Concerns\ScopesAgentReads;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Support\OneLine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * One project's shape: its columns and how full they are, the sprint it is in,
 * who is on it, and its labels — what an assistant needs before it can say
 * anything sensible about the work on it, or file and move cards correctly.
 */
#[Name('get_project')]
#[Title('Get Project')]
#[Description(<<<'TEXT'
Get an overview of one project board: its description, its columns (statuses)
with how many tasks are in each, the active sprint, its members, and its
labels. Name the project by its key (`ENG`) or name. Use `browse_workspace`
with list=projects to see which projects exist.
TEXT)]
#[IsReadOnly]
class GetProjectTool extends SnackTool
{
    use ResolvesProjectWork;
    use ScopesAgentReads;

    public function handle(Request $request): Response
    {
        $context = $this->readContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $project = $this->project($context, $request->get('project'));

        if ($project === null) {
            return Response::error($this->noSuchProject($context, $request->get('project')));
        }

        $counts = $project->boardTasks()
            ->selectRaw('board_column_id, count(*) as aggregate')
            ->groupBy('board_column_id')
            ->pluck('aggregate', 'board_column_id');

        $columns = $project->columns()->ordered()->get()
            ->map(fn (BoardColumn $column): string => sprintf(
                '- %s (%s): %d task(s)',
                $column->name,
                $column->category->value,
                (int) ($counts[$column->id] ?? 0),
            ))
            ->implode("\n");

        $sprint = $project->uses_sprints ? $project->activeSprint() : null;

        $members = $project->members()->with('user:id,name,username')->get()
            ->map(fn (ProjectMember $member): string => "{$member->user->name} (@{$member->user->username}, {$member->role->value})")
            ->implode(', ');

        $labels = $project->labels()->orderBy('name')->pluck('name')->implode(', ');

        $lines = array_filter([
            "{$project->key} · {$project->name}".($project->is_archived ? ' (archived)' : ''),
            $project->description,
            $project->uses_sprints ? 'Works in sprints.' : 'Kanban: no sprints.',
            match (true) {
                ! $project->uses_sprints => null,
                $sprint === null => 'No sprint is running; the board is empty until one starts. Tasks are in the backlog.',
                default => "Active sprint: {$sprint->name}"
                    .($sprint->goal ? " — goal: {$sprint->goal}" : '')
                    .($sprint->ends_at ? ", ends {$sprint->ends_at->toFormattedDateString()}" : ''),
            },
            "Columns on the board:\n{$columns}",
            'Members: '.($members !== '' ? $members : 'none'),
            $labels !== '' ? "Labels: {$labels}" : null,
            $this->agentSettings($project, $context->user),
        ]);

        return Response::text(implode("\n\n", $lines));
    }

    /**
     * The project's agent settings as they take effect — off while the project
     * or its channel is archived. The channel is named only to someone who can
     * see it, and its absence is not remarked on to anyone else: "a channel
     * you cannot see" would confirm that a private one exists.
     */
    private function agentSettings(Project $project, User $user): string
    {
        if (! $project->agentsEnabled()) {
            return 'Agents: off';
        }

        $channel = $project->agentChannel;

        if ($channel === null || Gate::forUser($user)->denies('view', $channel)) {
            return 'Agents: on';
        }

        $inProgress = $project->agentInProgressColumn->name ?? 'not set';
        $review = $project->agentReviewColumn->name ?? 'not set';

        return 'Agents: #'.OneLine::clean($channel->name)." (in progress: {$inProgress}, review: {$review})";
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('The project key (ENG) or name.')
                ->required(),
        ];
    }
}
