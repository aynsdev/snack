<?php

namespace App\Actions\Projects;

use App\Events\BoardChanged;
use App\Models\Project;
use App\Models\Release;
use Carbon\CarbonInterface;

/**
 * Planning a release, editing one, and taking one off the plan.
 *
 * Thin on purpose: a release is a name, two dates and a document, and the work
 * inside it is the tasks' own `release_id` rather than a list kept here. There
 * is no state to derive and nothing to enforce beyond what the schema already
 * does, which is why this is not the elaborate machine {@see SaveSprint} is.
 */
class SaveRelease
{
    /**
     * @param  array<string, mixed>  $attributes  as validated by ReleaseController
     */
    public function create(Project $project, array $attributes): Release
    {
        $release = $project->releases()->create([
            'name' => $attributes['name'],
            'notes' => $attributes['notes'] ?? null,
            'starts_on' => $attributes['starts_on'] ?? null,
            'ends_on' => $attributes['ends_on'] ?? null,
        ]);

        BoardChanged::dispatch($project);

        return $release;
    }

    /**
     * @param  array<string, mixed>  $attributes  as validated by ReleaseController
     */
    public function update(Release $release, array $attributes): Release
    {
        $release->fill(array_intersect_key($attributes, array_flip([
            'name', 'notes', 'starts_on', 'ends_on',
        ])));

        if ($release->isDirty()) {
            $release->save();

            BoardChanged::dispatch($release->project);
        }

        return $release;
    }

    /**
     * Shipping it, and taking that back.
     *
     * One toggle rather than two verbs on the row, because "released" is one
     * timestamp — and unreleasing has to exist: the button is a click away from
     * Edit, and a version marked shipped by mistake would otherwise be a lie
     * nobody could correct.
     *
     * Unfinished work in the release stays in it. A version that shipped with
     * things cut still shipped with them cut, and quietly moving them out would
     * rewrite what went out in it.
     *
     * `$at` records a release that went out earlier than it was marked; without
     * it, an already-released version keeps the date it first recorded.
     */
    public function markReleased(Release $release, bool $released, ?CarbonInterface $at = null): Release
    {
        $release->forceFill([
            'released_at' => $released ? ($at ?? $release->released_at ?? now()) : null,
        ])->save();

        BoardChanged::dispatch($release->project);

        return $release;
    }

    /**
     * Cancelling a release.
     *
     * The work survives it. `tasks.release_id` is `nullOnDelete`, so every card
     * that was going out in this version comes back unscheduled rather than
     * disappearing with the plan that named it — deleting a release means "not
     * like that", never "not at all".
     */
    public function delete(Release $release): void
    {
        $project = $release->project;

        $release->delete();

        BoardChanged::dispatch($project);
    }
}
