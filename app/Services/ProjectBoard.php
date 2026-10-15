<?php

namespace App\Services;

use App\Http\Resources\BoardColumnResource;
use App\Http\Resources\SprintResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\UserResource;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Release;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Assembles the two views of a project: the board, and the backlog.
 *
 * Centralized here for the reason {@see WorkspaceNavigator} is: these payloads
 * are what a project page *is*, they are rebuilt on every visit, and their query
 * count is a fixed cost the whole feature pays. Every read below is one query
 * with its relations eager-loaded, and nothing lazy-loads per card.
 *
 * # Why the flat list, and not a map of column to cards
 *
 * The board hands the client one array of tasks and one array of columns, and
 * the client groups them. Grouping server-side would mean a card arriving over
 * the socket has to be routed into the right bucket by a client that now holds
 * the same information twice — the grouping and the card's own column id — and
 * the two would drift the first time a move raced a refresh.
 */
class ProjectBoard
{
    /**
     * Everything the board page renders.
     *
     * @return array<string, mixed>
     */
    public function board(Project $project, User $viewer): array
    {
        $sprint = $project->uses_sprints ? $project->activeSprint() : null;

        $tasks = $project->boardTasks()
            ->withCardData()
            ->ordered()
            ->get();

        return [
            'columns' => BoardColumnResource::collection(
                $project->columns()->ordered()->get(),
            )->resolve(request()),

            'tasks' => TaskResource::collection($tasks)->resolve(request()),

            'sprint' => $sprint === null
                ? null
                : (new SprintResource($sprint))->resolve(request()),

            /*
             * The sprints a lead can start from the board's own header. Only the
             * planned ones: the running sprint is already `sprint` above, and a
             * completed one cannot be restarted.
             */
            'plannedSprints' => $project->uses_sprints
                ? SprintResource::collection(
                    $project->sprints()->whereNull('started_at')->ordered()->get(),
                )->resolve(request())
                : [],

            /*
             * How much work is sitting off the board, and only when there is no
             * board to sit on.
             *
             * A scrum project with no running sprint draws nothing at all, and
             * an empty board with cards in the database is the one state a
             * reader cannot tell apart from a broken one. The count is what
             * makes the difference sayable — "your work is in the backlog"
             * rather than three empty columns. Null when a sprint is running,
             * so the query is not paid for on every ordinary board load.
             */
            'backlogCount' => $project->uses_sprints && $sprint === null
                ? $project->tasks()->whereNull('sprint_id')->count()
                : null,

            'members' => $this->members($project),
            'labels' => $this->labels($project),
            'releases' => $this->releases($project),

            // What the viewer may do here, so the board can hide the controls it
            // would only refuse. Re-checked on every write regardless.
            'canContribute' => $viewer->can('contribute', $project),
            'canManage' => $viewer->can('update', $project),
        ];
    }

    /**
     * Everything the backlog page renders: the open sprints with their contents,
     * and the uncommitted work underneath.
     *
     * @return array<string, mixed>
     */
    public function backlog(Project $project, User $viewer): array
    {
        /** @var Collection<int, Sprint> $sprints */
        $sprints = $project->uses_sprints
            ? $project->sprints()->open()->ordered()->get()
            : collect();

        /*
         * One query for every card in the picture rather than one per sprint
         * plus one for the backlog: a project planning three sprints ahead would
         * otherwise cost four board-sized reads to draw one page.
         */
        $tasks = $project->tasks()
            ->where(fn ($query) => $query
                ->whereNull('sprint_id')
                ->orWhereIn('sprint_id', $sprints->pluck('id')))
            ->withCardData()
            ->ordered()
            ->get();

        return [
            'columns' => BoardColumnResource::collection(
                $project->columns()->ordered()->get(),
            )->resolve(request()),

            'sprints' => SprintResource::collection($sprints)->resolve(request()),
            'tasks' => TaskResource::collection($tasks)->resolve(request()),

            'members' => $this->members($project),
            'labels' => $this->labels($project),
            'releases' => $this->releases($project),

            'canContribute' => $viewer->can('contribute', $project),
            'canManage' => $viewer->can('update', $project),
        ];
    }

    /**
     * The people on the project — which is exactly the set that can be assigned
     * its tasks, and so exactly what the assignee picker offers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function members(Project $project): array
    {
        return $project->members()
            ->with('user')
            ->get()
            ->map(fn (ProjectMember $membership): array => [
                // The membership row's own id, which is what the member routes
                // are keyed by — see Projects\ProjectMemberController for why
                // they address the membership rather than the person.
                'id' => $membership->id,
                'user' => (new UserResource($membership->user))->resolve(request()),
                'role' => $membership->role->value,
                'joined_at' => $membership->joined_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function labels(Project $project): array
    {
        return $project->labels()->get()
            ->map(fn ($label): array => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
            ])
            ->all();
    }

    /**
     * The project's releases, for the picker on a card — a name and an id, not
     * the notes and contents the releases page draws.
     *
     * @return array<int, array<string, mixed>>
     */
    private function releases(Project $project): array
    {
        return $project->releases()->ordered()->get()
            ->map(fn (Release $release): array => [
                'id' => $release->id,
                'name' => $release->name,
            ])
            ->all();
    }
}
