<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PlacesTasks;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * One release and the work going out in it — a release checklist.
 */
#[Name('get_release')]
#[Title('Get Release')]
#[Description(<<<'TEXT'
Get one release by name (such as v3.2.1): its state, dates, notes, and every
task in it with its status and assignee — a checklist of what is done and
what is left.
TEXT)]
#[IsReadOnly]
class GetReleaseTool extends SnackTool
{
    use PlacesTasks;
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

        $release = $this->releaseNamed($project, $request->get('release'));

        if (is_string($release) || $release === null) {
            return Response::error(str_replace(' Nothing was changed.', '', $release ?? $this->releaseOptions($project)));
        }

        $release->loadCount(['tasks', 'tasks as completed_tasks_count' => fn ($query) => $query->whereNotNull('completed_at')]);

        $tasks = $release->tasks()
            ->with(['column:id,name', 'assignee:id,username'])
            ->orderBy('number')
            ->get()
            ->map(fn (Task $task): string => sprintf(
                '- [%s] %s — %s [%s · %s] %s',
                $task->isComplete() ? 'x' : ' ',
                $project->reference($task->number),
                $task->title,
                $task->column->name,
                $task->type->value,
                $task->assignee !== null ? "@{$task->assignee->username}" : 'unassigned',
            ))
            ->implode("\n");

        $notes = $release->notes !== null && trim($release->notes) !== '' ? trim($release->notes) : '(none)';

        return Response::text(implode("\n\n", [
            "{$project->key} release ".ltrim(ListReleasesTool::releaseLine($release), '- '),
            "Notes:\n{$notes}",
            "Tasks:\n".($tasks !== '' ? $tasks : '(none yet)'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('The project key (ENG) or name.')->required(),
            'release' => $schema->string()->description('The release name, such as v3.2.1.')->required(),
        ];
    }
}
