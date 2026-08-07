<?php

namespace App\Actions\Projects;

use App\Enums\ColumnCategory;
use App\Events\BoardChanged;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Support\Positions;
use Illuminate\Validation\ValidationException;

/**
 * Adds a column to a board, renames one, and moves one left or right.
 *
 * A column is the part of the board a team shapes to fit how they actually work
 * — the reference board in the brief has a "To fix" between testing and
 * releasing — so this is expected to be used, not a one-time setup step.
 */
class SaveBoardColumn
{
    /**
     * @param  array{
     *     name: string,
     *     category?: string,
     *     wip_limit?: int|null,
     *     before_column_id?: int|null,
     *     after_column_id?: int|null,
     *  }  $attributes
     */
    public function create(Project $project, array $attributes): BoardColumn
    {
        $column = BoardColumn::create([
            'project_id' => $project->id,
            'name' => $attributes['name'],
            'category' => $attributes['category'] ?? ColumnCategory::Unstarted->value,
            'position' => $this->placement($project, $attributes),
            'wip_limit' => $attributes['wip_limit'] ?? null,
        ]);

        BoardChanged::dispatch($project);

        return $column;
    }

    /**
     * Where a new column goes.
     *
     * The end of the board unless the caller named the columns it belongs
     * between — which the board's own add button does, because a status added
     * there is nearly always one more step *before* Done, and a column landing
     * to the right of Done is a board that reads backwards until somebody
     * reorders it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function placement(Project $project, array $attributes): int
    {
        $beforeId = $attributes['before_column_id'] ?? null;
        $afterId = $attributes['after_column_id'] ?? null;

        if ($beforeId === null && $afterId === null) {
            return Positions::between($project->columns()->max('position'), null);
        }

        $neighbours = $project->columns()
            ->whereIn('id', array_filter([$beforeId, $afterId]))
            ->get()
            ->keyBy('id');

        $before = $beforeId === null ? null : $neighbours->get((int) $beforeId)?->position;
        $after = $afterId === null ? null : $neighbours->get((int) $afterId)?->position;

        if (Positions::needsRebalance($before, $after)) {
            $this->spread($project);

            return $this->placement($project, $attributes);
        }

        return Positions::between($before, $after);
    }

    /**
     * @param  array{
     *     name?: string,
     *     category?: string,
     *     wip_limit?: int|null,
     *     before_column_id?: int|null,
     *     after_column_id?: int|null,
     *  }  $attributes
     */
    public function update(BoardColumn $column, array $attributes): BoardColumn
    {
        $project = $column->project;

        $this->guardLastOfCategory($column, $attributes);

        $column->fill(array_intersect_key($attributes, array_flip([
            'name', 'category', 'wip_limit',
        ])));

        if (array_key_exists('before_column_id', $attributes) || array_key_exists('after_column_id', $attributes)) {
            $column->position = $this->positionBetween(
                $project,
                $column,
                $attributes['before_column_id'] ?? null,
                $attributes['after_column_id'] ?? null,
            );
        }

        $column->save();

        BoardChanged::dispatch($project);

        return $column;
    }

    /**
     * A board must keep at least one Done column and one Unstarted one.
     *
     * Both are load-bearing: {@see CompleteSprint} asks which tasks are finished,
     * and {@see SaveTask} asks where a new task lands. A board with no Done
     * column would silently carry every task forward for ever, and one with no
     * unstarted column would file new work into whatever happened to be first.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function guardLastOfCategory(BoardColumn $column, array $attributes): void
    {
        $category = $attributes['category'] ?? null;

        if ($category === null || $category === $column->category->value) {
            return;
        }

        foreach ([ColumnCategory::Done, ColumnCategory::Unstarted] as $required) {
            if ($column->category !== $required) {
                continue;
            }

            $others = $column->project->columns()
                ->where('category', $required->value)
                ->whereKeyNot($column->id)
                ->exists();

            if (! $others) {
                throw ValidationException::withMessages([
                    'category' => "A board needs at least one “{$required->label()}” column.",
                ]);
            }
        }
    }

    private function positionBetween(
        Project $project,
        BoardColumn $column,
        ?int $beforeId,
        ?int $afterId,
    ): int {
        $neighbours = $project->columns()
            ->whereIn('id', array_filter([$beforeId, $afterId]))
            ->whereKeyNot($column->id)
            ->get()
            ->keyBy('id');

        $before = $beforeId === null ? null : $neighbours->get($beforeId)?->position;
        $after = $afterId === null ? null : $neighbours->get($afterId)?->position;

        if (! Positions::needsRebalance($before, $after)) {
            return Positions::between($before, $after);
        }

        $this->spread($project);

        return $this->positionBetween($project, $column, $beforeId, $afterId);
    }

    /**
     * Spreads every column of a board back out.
     *
     * A board has a handful of columns, so renumbering all of them is cheap and
     * there is no reason for the fractional-insert dance tasks need.
     */
    private function spread(Project $project): void
    {
        $columns = $project->columns()->ordered()->get();
        $positions = Positions::spread($columns);

        foreach ($columns as $index => $each) {
            $each->forceFill(['position' => $positions[$index]])->save();
        }
    }
}
