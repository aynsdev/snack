<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\Sprint;
use App\Support\Positions;

/**
 * Plans a sprint, and edits one.
 *
 * The dates written here are the team's *intent*. When the sprint actually
 * starts and finishes is recorded separately by {@see StartSprint} and
 * {@see CompleteSprint}, so a fortnight that ran three days long says so rather
 * than having its plan quietly rewritten to match what happened.
 */
class SaveSprint
{
    /**
     * @param  array{
     *     name?: string,
     *     goal?: string|null,
     *     starts_at?: string|null,
     *     ends_at?: string|null,
     *  }  $attributes
     */
    public function create(Project $project, array $attributes): Sprint
    {
        $last = $project->sprints()->max('position');

        return Sprint::create([
            'project_id' => $project->id,
            'name' => $attributes['name'] ?? $this->suggestName($project),
            'goal' => $attributes['goal'] ?? null,
            'starts_at' => $attributes['starts_at'] ?? null,
            'ends_at' => $attributes['ends_at'] ?? null,
            'position' => Positions::between($last, null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Sprint $sprint, array $attributes): Sprint
    {
        $sprint->fill(array_intersect_key($attributes, array_flip([
            'name', 'goal', 'starts_at', 'ends_at',
        ])))->save();

        return $sprint;
    }

    /**
     * "Sprint 4" — the next number after however many the project has had.
     *
     * Counted rather than parsed out of the last name: a team that renamed one
     * to "Launch week" should not have the next one called "Launch week 2".
     */
    private function suggestName(Project $project): string
    {
        return 'Sprint '.($project->sprints()->count() + 1);
    }
}
