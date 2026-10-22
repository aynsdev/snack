<?php

namespace App\Http\Controllers\Projects;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\ProjectBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The project at a glance: how much work there is, where it sits, and what has
 * moved lately.
 *
 * # Counted in the database, not in PHP
 *
 * Every panel here is an aggregate over the whole project — a board of a
 * thousand cards is a thousand rows nobody wants serialized to draw five bars.
 * Each panel is one grouped query returning a handful of rows; the only cards
 * that travel whole are the few in the activity list.
 *
 * # Why the windows are seven days
 *
 * The tiles answer "is this project moving", which is a question about the last
 * week, not about all time. A count with no window would be a number that only
 * ever goes up.
 */
class ProjectSummaryController extends Controller
{
    public function __construct(private readonly ProjectBoard $boards) {}

    /** The window every "lately" on this page is measured over. */
    private const WINDOW_DAYS = 7;

    public function show(Workspace $workspace, Project $project, Request $request): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('projects/summary', [
            'project' => (new ProjectResource($project))->resolve($request),
            'members' => $this->boards->members($project),

            'recent' => $this->recentCounts($project),
            'statuses' => $this->byStatus($project),
            'priorities' => $this->byPriority($project),
            'types' => $this->byType($project),
            'activity' => $this->activity($project),

            'canManage' => $request->user()->can('update', $project),
        ]);
    }

    /**
     * The four tiles: what has moved in the last week, and what is about to be
     * late.
     *
     * One query with conditional counts rather than four round trips — they are
     * four questions about the same table over the same window.
     *
     * @return array<string, int>
     */
    protected function recentCounts(Project $project): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $soon = now()->addDays(self::WINDOW_DAYS);

        /** @var object{completed: int, updated: int, created: int, due_soon: int}|null $row */
        $row = $project->tasks()
            ->toBase()
            ->selectRaw('count(*) filter (where completed_at >= ?) as completed', [$since])
            ->selectRaw('count(*) filter (where updated_at >= ?) as updated', [$since])
            ->selectRaw('count(*) filter (where created_at >= ?) as created', [$since])

            /*
             * Due soon is forward-looking and excludes what is finished: a card
             * that shipped yesterday is not something to worry about tomorrow.
             * Anything already past its date counts too — it is not less urgent
             * for being late.
             */
            ->selectRaw(
                'count(*) filter (where due_on is not null and due_on <= ? and completed_at is null) as due_soon',
                [$soon->toDateString()],
            )
            ->first();

        return [
            'completed' => (int) ($row->completed ?? 0),
            'updated' => (int) ($row->updated ?? 0),
            'created' => (int) ($row->created ?? 0),
            'due_soon' => (int) ($row->due_soon ?? 0),
        ];
    }

    /**
     * Every column of the board with how much is sitting in it, in board order.
     *
     * Columns with nothing in them are kept. An empty "In review" is a fact
     * about the project — dropping it would make the same board draw a
     * different shape on a quiet week.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function byStatus(Project $project): array
    {
        $counts = $project->tasks()
            ->toBase()
            ->select('board_column_id', DB::raw('count(*) as total'))
            ->groupBy('board_column_id')
            ->pluck('total', 'board_column_id');

        return $project->columns()->ordered()->get()
            ->map(fn (BoardColumn $column): array => [
                'id' => $column->id,
                'name' => $column->name,
                'category' => $column->category->value,
                'count' => (int) ($counts[$column->id] ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function byPriority(Project $project): array
    {
        $counts = $project->tasks()
            ->toBase()
            ->select('priority', DB::raw('count(*) as total'))
            ->groupBy('priority')
            ->pluck('total', 'priority');

        // Highest first, and every level present: the shape of the chart should
        // not change with which priorities happen to be in use this month.
        return collect(TaskPriority::cases())
            ->map(fn (TaskPriority $priority): array => [
                'value' => $priority->value,
                'label' => $priority->label(),
                'count' => (int) ($counts[$priority->value] ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function byType(Project $project): array
    {
        $counts = $project->tasks()
            ->toBase()
            ->select('type', DB::raw('count(*) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');

        return collect(TaskType::cases())
            ->map(fn (TaskType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'count' => (int) ($counts[$type->value] ?? 0),
            ])
            ->all();
    }

    /**
     * What has moved lately.
     *
     * The cards themselves, most recently touched first — not a field-by-field
     * history, because none is stored. Saying "TES-14 changed an hour ago" is
     * the whole of what this application knows, and inventing "Alicia changed
     * the assignee" from an `updated_at` would be a story rather than a record.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function activity(Project $project): array
    {
        return $project->tasks()
            ->with(['assignee', 'column'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'reference' => $task->reference(),
                'title' => $task->title,
                'type' => $task->type->value,
                'status' => $task->column->name,
                'is_complete' => $task->isComplete(),
                'assignee' => $task->assignee?->name,
                'updated_at' => $task->updated_at?->toIso8601String(),
            ])
            ->all();
    }
}
