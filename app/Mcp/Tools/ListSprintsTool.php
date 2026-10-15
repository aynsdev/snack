<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Models\Sprint;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A project's sprints, so an assistant can name one to file work into.
 */
#[Name('list_sprints')]
#[Title('List Sprints')]
#[Description(<<<'TEXT'
List a project's sprints: the active one, planned ones, and (with
`include_completed`) finished ones, with dates, goal, and progress. Any open
sprint's name works as `sprint` on `create_task`, `update_task`, and
`update_tasks`, as do "active" and "backlog".
TEXT)]
#[IsReadOnly]
class ListSprintsTool extends SnackTool
{
    use ResolvesProjectWork;

    public function handle(Request $request): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $project = $this->project($context, $request->get('project'));

        if ($project === null) {
            return Response::error($this->noSuchProject($context, $request->get('project')));
        }

        if (! $project->uses_sprints) {
            return Response::text("{$project->key} does not use sprints; its board is everything not finished.");
        }

        $rows = $project->sprints()
            ->when(! $request->get('include_completed', false), fn ($query) => $query->open())
            ->withCount(['tasks', 'tasks as completed_tasks_count' => fn ($query) => $query->whereNotNull('completed_at')])
            ->ordered()
            ->get()
            ->map(function (Sprint $sprint): string {
                $dates = array_filter([
                    $sprint->starts_at?->toFormattedDateString(),
                    $sprint->ends_at?->toFormattedDateString(),
                ]);

                return sprintf(
                    '- %s [%s%s]%s · %d of %d done%s',
                    $sprint->name,
                    $sprint->state()->value,
                    $sprint->isOverdue() ? ', overdue' : '',
                    $dates === [] ? '' : ' · '.implode(' – ', $dates),
                    (int) $sprint->completed_tasks_count,
                    (int) $sprint->tasks_count,
                    $sprint->goal !== null && trim($sprint->goal) !== '' ? "\n  Goal: ".trim($sprint->goal) : '',
                );
            })
            ->all();

        $backlog = $project->tasks()->whereNull('sprint_id')->whereNull('completed_at')->count();

        return Response::text(($rows === [] ? "{$project->key} has no open sprints." : "Sprints on {$project->key}:\n".implode("\n", $rows))
            ."\n\nBacklog: {$backlog} unfinished task(s).");
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('The project key (ENG) or name.')->required(),
            'include_completed' => $schema->boolean()->description('Include finished sprints. Defaults to false.'),
        ];
    }
}
