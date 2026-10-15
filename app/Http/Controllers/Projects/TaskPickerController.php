<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cards to offer in a picker on another card — its parent, or a task to link.
 *
 * A search rather than a list on the board payload: a project is easily a
 * thousand cards, and a dropdown of every one of them on every board load is
 * a payload nobody scrolls. Matches the number ("1499", "PWA-1499") or the
 * title, within the card's own project.
 *
 * For `purpose=parent` the card itself and everything beneath it are left
 * out, since choosing any of them would make a loop — the same rule SaveTask
 * enforces when the choice comes back — and epics never have a parent, so an
 * epic gets nothing.
 */
class TaskPickerController extends Controller
{
    private const LIMIT = 20;

    public function index(Workspace $workspace, Task $task, Request $request): JsonResponse
    {
        $this->authorize('view', $task);

        $project = $task->project;
        $search = trim((string) $request->query('q', ''));
        $forParent = $request->query('purpose') === 'parent';

        if ($forParent && $task->type->isContainer()) {
            return response()->json(['tasks' => []]);
        }

        $number = preg_match('/^(?:[A-Za-z][A-Za-z0-9]*-)?(\d+)$/', $search, $parts) ? (int) $parts[1] : null;

        $candidates = $project->tasks()
            ->whereKeyNot($task->id)
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('title', 'ilike', '%'.$search.'%')
                ->when($number !== null, fn ($byNumber) => $byNumber->orWhere('number', $number))))
            ->orderByRaw('completed_at IS NOT NULL')
            ->orderByDesc('number')
            ->limit(self::LIMIT * 2)
            ->get(['id', 'project_id', 'number', 'title', 'type', 'completed_at', 'parent_task_id']);

        if ($forParent) {
            $candidates = $candidates->reject(fn (Task $candidate): bool => $candidate->isOrDescendsFrom($task->id));
        }

        return response()->json([
            'tasks' => $candidates->take(self::LIMIT)->values()->map(fn (Task $candidate): array => [
                'id' => $candidate->id,
                'reference' => $project->reference($candidate->number),
                'title' => $candidate->title,
                'type' => $candidate->type->value,
            ])->all(),
        ]);
    }
}
