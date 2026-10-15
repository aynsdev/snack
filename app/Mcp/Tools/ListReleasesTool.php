<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Models\Release;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A project's versions, the table on its Releases page as text.
 */
#[Name('list_releases')]
#[Title('List Releases')]
#[Description(<<<'TEXT'
List a project's releases (versions such as v3.2.1), soonest first, with
whether each has gone out, its target date, and how much of its work is done.

- `status`: `planned` for releases not yet out, `released` for ones that
  have, or `all` (default).

Use `get_release` to see the tasks in one.
TEXT)]
#[IsReadOnly]
class ListReleasesTool extends SnackTool
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

        $rows = $project->releases()
            ->when($request->get('status') === 'planned', fn ($query) => $query->whereNull('released_at'))
            ->when($request->get('status') === 'released', fn ($query) => $query->whereNotNull('released_at'))
            ->withCount(['tasks', 'tasks as completed_tasks_count' => fn ($query) => $query->whereNotNull('completed_at')])
            ->ordered()
            ->get()
            ->map(fn (Release $release): string => static::releaseLine($release))
            ->all();

        return Response::text($rows === []
            ? "{$project->key} has no releases that match."
            : "Releases on {$project->key}:\n".implode("\n", $rows));
    }

    /**
     * One release on one line: name, state, dates, progress.
     */
    public static function releaseLine(Release $release): string
    {
        $state = $release->isReleased()
            ? 'released '.$release->released_at->toFormattedDateString()
            : ($release->isOverdue() ? 'planned, overdue' : 'planned');

        $dates = array_filter([
            $release->starts_on !== null ? 'starts '.$release->starts_on->toFormattedDateString() : null,
            $release->ends_on !== null ? 'target '.$release->ends_on->toFormattedDateString() : null,
        ]);

        return sprintf(
            '- %s [%s]%s · %d of %d done',
            $release->name,
            $state,
            $dates === [] ? '' : ' · '.implode(', ', $dates),
            (int) $release->completed_tasks_count,
            (int) $release->tasks_count,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('The project key (ENG) or name.')->required(),
            'status' => $schema->string()->enum(['planned', 'released', 'all']),
        ];
    }
}
