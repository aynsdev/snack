<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DeleteBoardColumn;
use App\Actions\Projects\SaveBoardColumn;
use App\Enums\ColumnCategory;
use App\Http\Controllers\Controller;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The board's own shape: its columns.
 *
 * A lead's endpoints — `manageColumns` is {@see App\Policies\ProjectPolicy::update()}
 * — because a column is not a piece of work, it is the process everybody's work
 * moves through.
 */
class BoardColumnController extends Controller
{
    public function store(
        Workspace $workspace,
        Project $project,
        Request $request,
        SaveBoardColumn $saveColumn,
    ): RedirectResponse {
        $this->authorize('update', $project);

        $saveColumn->create($project, $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'category' => ['required', Rule::enum(ColumnCategory::class)],
            'wip_limit' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]));

        return back()->with('success', 'Column added.');
    }

    public function update(
        Workspace $workspace,
        Project $project,
        BoardColumn $column,
        Request $request,
        SaveBoardColumn $saveColumn,
    ): RedirectResponse {
        $this->authorize('update', $project);

        $saveColumn->update($column, $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:40'],
            'category' => ['sometimes', Rule::enum(ColumnCategory::class)],
            'wip_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],

            // Reordering. Ids rather than a position, for the reason a card move
            // sends ids: the numbers are the server's business.
            'before_column_id' => ['sometimes', 'nullable', 'integer'],
            'after_column_id' => ['sometimes', 'nullable', 'integer'],
        ]));

        return back()->with('success', 'Column updated.');
    }

    /**
     * Removes a column, moving whatever is in it to `move_to_column_id` — or, if
     * that is not given, to the column beside it. See {@see DeleteBoardColumn}
     * for why the cards must not go with it.
     */
    public function destroy(
        Workspace $workspace,
        Project $project,
        BoardColumn $column,
        Request $request,
        DeleteBoardColumn $deleteColumn,
    ): RedirectResponse {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'move_to_column_id' => ['nullable', 'integer'],
        ]);

        $deleteColumn->handle($column, $validated['move_to_column_id'] ?? null);

        return back()->with('success', 'Column removed.');
    }
}
