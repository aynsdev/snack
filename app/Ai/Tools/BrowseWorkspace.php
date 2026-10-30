<?php

namespace App\Ai\Tools;

use App\Models\Channel;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentReadScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * "What is there?"
 *
 * The workspace's own furniture — its channels, its people, its projects — so
 * that Bite can resolve "the design channel" or "Ada" or "the mobile project"
 * into something the other tools take, and can answer structural questions
 * without searching for them.
 *
 * # Why one tool for three lists
 *
 * They are asked for the same reason and answered the same way: a short,
 * complete, cheap enumeration that mostly exists so the next tool call can be
 * specific. Three tools would be three descriptions for the model to weigh on
 * every turn, and the failure mode of a crowded tool list is that the model
 * picks the wrong one and then apologizes.
 *
 * # Each list has its own audience
 *
 * Channels use `Channel::scopeVisibleTo`, the same rule the channel browser
 * uses, so private channels the asker is not in are absent rather than listed
 * and locked. Projects use membership, plus the allowance
 * {@see App\Policies\ProjectPolicy} makes for non-guests, so this agrees with
 * what the projects screen would show. People are the workspace's human members
 * — bots are excluded, because "who is on the team" should not answer "Bite".
 */
class BrowseWorkspace extends BiteTool implements Tool
{
    public function description(): Stringable|string
    {
        return <<<'TEXT'
        List what exists in this workspace. Use this to resolve a name someone
        used into a real channel, person, or project before calling another tool,
        and to answer questions like "what channels are there?" or "who is on the
        team?".

        Ask for one of:
        - `channels` — the channels this person can see, with their topics.
        - `people` — the workspace's members, with their handles and job titles.
        - `projects` — the projects this person can see, with their keys. You need
          a project key to create a task.

        Everything returned is already limited to what the person asking can see.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = $this->limit($this->arg($request, 'limit'), 50, 100);
        $term = $this->term($this->arg($request, 'query'), 80);

        return match ($this->arg($request, 'list')) {
            'channels' => $this->channels($term, $limit),
            'people' => $this->people($term, $limit),
            'projects' => $this->projects($term, $limit),
            default => 'Ask for one of: channels, people, projects.',
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'list' => $schema->string()
                ->description('Which list to return.')
                ->enum(['channels', 'people', 'projects'])
                ->required(),
            'query' => $schema->string()
                ->description('Optional: only entries whose name matches these words.'),
            'limit' => $schema->integer()
                ->description('How many entries to return. Defaults to 50, maximum 100.')
                ->min(1)
                ->max(100),
        ];
    }

    private function channels(string $term, int $limit): string
    {
        $role = $this->context->role();

        if ($role === null) {
            return 'This person is not a member of this workspace.';
        }

        $channels = Channel::query()
            ->where('workspace_id', $this->context->workspace->id)
            ->visibleTo($this->context->user, $role)
            ->when($this->context->readScope, fn (Builder $query, AgentReadScope $scope) => $scope->constrainChannels($query))
            ->notArchived()
            ->when($term !== '', fn (Builder $query) => $query->where('name', 'ilike', '%'.$term.'%'))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return $this->list(
            $channels->map(function (Channel $channel): string {
                $topic = $channel->topic !== null && $channel->topic !== ''
                    ? " — {$channel->topic}"
                    : '';

                return "#{$channel->name} ({$channel->type->value}){$topic}";
            })->all(),
            'There are no channels here that this person can see.',
        );
    }

    private function people(string $term, int $limit): string
    {
        $directory = Gate::forUser($this->context->user)->allows('viewMembers', $this->context->workspace)
            && ($this->context->readScope?->allowsMemberDirectory() ?? true);

        if (! $directory) {
            return 'Guests cannot browse the member directory, so the people list is not available here.';
        }

        /*
         * `activeUsers` rather than `users`: a suspended member is treated as a
         * non-member everywhere else in Snack, and offering one as somebody to
         * assign work to is how a card ends up owned by an account that cannot
         * sign in.
         */
        $people = $this->context->workspace
            ->activeUsers()
            ->humans()
            ->when($term !== '', fn (Builder $query) => $query->where(
                fn (Builder $group) => $group
                    ->where('name', 'ilike', '%'.$term.'%')
                    ->orWhere('username', 'ilike', '%'.$term.'%'),
            ))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return $this->list(
            $people->map(function (User $person): string {
                $title = $person->title !== null && $person->title !== ''
                    ? " — {$person->title}"
                    : '';

                return "{$person->name} (@{$person->username}){$title}";
            })->all(),
            'No members match.',
        );
    }

    private function projects(string $term, int $limit): string
    {
        /*
         * A guest is confined to the channels they were invited to, and
         * ProjectPolicy spells that as a 404 rather than a 403 — whether this
         * workspace runs projects at all is not something to confirm to a
         * contractor. Saying "you do not have access" here rather than listing
         * nothing keeps Bite from inventing an explanation for the silence.
         */
        if ($this->context->isGuest()) {
            return 'This person is a guest and cannot see projects.';
        }

        $projects = Project::query()
            ->where('workspace_id', $this->context->workspace->id)
            ->when($this->context->readScope, fn (Builder $query, AgentReadScope $scope) => $scope->constrainProjects($query))
            ->notArchived()
            ->when($term !== '', fn (Builder $query) => $query->where(
                fn (Builder $group) => $group
                    ->where('name', 'ilike', '%'.$term.'%')
                    ->orWhere('key', 'ilike', '%'.$term.'%'),
            ))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return $this->list(
            $projects->map(function (Project $project): string {
                $description = $project->description !== null && $project->description !== ''
                    ? " — {$project->description}"
                    : '';

                return "{$project->key} · {$project->name}{$description}";
            })->all(),
            'There are no projects here that this person can see.',
        );
    }
}
