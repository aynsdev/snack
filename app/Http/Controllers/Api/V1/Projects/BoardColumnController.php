<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Projects\DeleteBoardColumn;
use App\Actions\Projects\SaveBoardColumn;
use App\Enums\ColumnCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\BoardColumnResource;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The board's columns — a lead's endpoints, as on the web
 * ({@see \App\Http\Controllers\Projects\BoardColumnController}).
 *
 * Placement is by neighbouring column ids rather than a position number, so the
 * server works out the numbers against the board as it is now.
 */
class BoardColumnController extends Controller
{
    public function store(
        Workspace $workspace,
        Project $project,
        Request $request,
        SaveBoardColumn $saveColumn,
    ): JsonResponse {
        $this->authorize('update', $project);

        $column = $saveColumn->create($project, $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'category' => ['required', Rule::enum(ColumnCategory::class)],
            'wip_limit' => ['nullable', 'integer', 'min:1', 'max:999'],
            'before_column_id' => ['sometimes', 'nullable', 'integer'],
            'after_column_id' => ['sometimes', 'nullable', 'integer'],
        ]));

        return (new BoardColumnResource($column))->response()->setStatusCode(201);
    }

    public function update(
        Workspace $workspace,
        Project $project,
        BoardColumn $column,
        Request $request,
        SaveBoardColumn $saveColumn,
    ): BoardColumnResource {
        $this->authorize('update', $project);

        return new BoardColumnResource($saveColumn->update($column, $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:40'],
            'category' => ['sometimes', Rule::enum(ColumnCategory::class)],
            'wip_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'before_column_id' => ['sometimes', 'nullable', 'integer'],
            'after_column_id' => ['sometimes', 'nullable', 'integer'],
        ])));
    }

    /**
     * Removes a column, moving its cards to `move_to_column_id` or the column
     * beside it — see {@see DeleteBoardColumn}.
     */
    public function destroy(
        Workspace $workspace,
        Project $project,
        BoardColumn $column,
        Request $request,
        DeleteBoardColumn $deleteColumn,
    ): Response {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'move_to_column_id' => ['nullable', 'integer'],
        ]);

        $deleteColumn->handle($column, $validated['move_to_column_id'] ?? null);

        return response()->noContent();
    }
}
