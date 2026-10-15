<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\LinkTask;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskLink;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web links on a card — pull requests, designs, incidents.
 *
 * Adding and removing one is working the card, the same right as editing it.
 */
class TaskLinkController extends Controller
{
    public function store(Workspace $workspace, Task $task, Request $request, LinkTask $linkTask): JsonResponse
    {
        $this->authorize('update', $task);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        $link = $linkTask->addLink($task, $request->user(), $validated['url'], $validated['title'] ?? null);

        return response()->json(['link' => TaskResource::link($link)], 201);
    }

    public function destroy(Workspace $workspace, Task $task, TaskLink $link, LinkTask $linkTask): JsonResponse
    {
        $this->authorize('update', $task);

        $linkTask->removeLink($link);

        return response()->json(['deleted' => true]);
    }
}
