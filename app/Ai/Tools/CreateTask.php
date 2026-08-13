<?php

namespace App\Ai\Tools;

use App\Actions\Projects\SaveTask;
use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Files an issue on a project board.
 *
 * # The only thing Bite can change
 *
 * Every other tool reads. This one writes, and it writes something that shows
 * up on a board other people work from — so it is the one that asks first. The
 * tool pauses before executing and the person who summoned Bite gets a card
 * with the exact title, project, and assignee it is about to use, and a button.
 * See {@see App\Http\Controllers\Ai\BiteApprovalController} for the resume.
 *
 * Approval is not a substitute for authorization. A person clicking "create"
 * cannot approve a task on a project they may not contribute to: the policy is
 * checked in {@see self::handle()}, after the click, against the asker rather
 * than against Bite. The card is a confirmation, not a grant.
 *
 * # The asker is the reporter
 *
 * Not Bite. A board full of cards reported by a robot is a board where nobody
 * knows who wanted the thing, and the audit trail for "who filed this" has to
 * point at a person. Bite is the instrument, not the author — the same reason
 * every read here happens as the asker too.
 */
class CreateTask extends BiteTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Create a task (an issue) on a project board.

        Before calling this you should:
        1. Search with `search_tasks` to check the thing has not already been
           filed, and say so instead of filing a duplicate.
        2. Use `browse_workspace` if you are unsure which projects exist.

        Naming the project: pass its key (`ENG`) or its name (`Engineering`) —
        whichever the person used. If what they said matches nothing, this tool
        answers with the projects that do exist so you can ask them which one
        they meant. Never guess between two similarly named boards; ask.

        Assigning it: pass the person's @username or their name. They have to be
        a member of that project. If they are not, the task is still filed and
        this tool tells you so — pass that on rather than claiming it is assigned.

        Write the title as a specific, actionable sentence — what is wrong or
        what should be built — not a restatement of the person's message. Put the
        context, including a link back to what was said, in the description.

        The person will be shown what you are about to create and must approve it,
        so do not ask them for confirmation yourself — just call the tool.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $key = $this->arg($request, 'project');
        $project = $this->project($key);

        if ($project === null) {
            return $this->noSuchProject($key);
        }

        /*
         * After the approval, not before it. The click says the person wants
         * this; the policy says whether they may — and a contributor's rights
         * can have been taken away between Bite proposing the card and them
         * pressing the button.
         */
        $allowed = Gate::forUser($this->context->user)->inspect('create', [Task::class, $project]);

        if ($allowed->denied()) {
            return "This person cannot create tasks on {$project->key}: {$allowed->message()}";
        }

        $assignment = $this->resolveAssignee($project, $this->arg($request, 'assignee'));

        $task = app(SaveTask::class)->create(
            project: $project,
            reporter: $this->context->user,
            attributes: [
                'title' => $this->term($this->arg($request, 'title'), 200) ?: 'Untitled',
                'description' => $this->description_($this->arg($request, 'description')),
                'type' => $this->type($this->arg($request, 'type')),
                'priority' => $this->priority($this->arg($request, 'priority')),
                'assignee_id' => $assignment['id'],
            ],
        );

        return trim(sprintf(
            'Created %s — %s on %s. %s Tell the person it is filed, using that reference.',
            $task->reference(),
            $task->title,
            $project->name,
            $assignment['note'],
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('The project to file this on: its key (ENG) or its name (Engineering).')
                ->required(),
            'title' => $schema->string()
                ->description('A specific, actionable one-line summary.')
                ->required(),
            'description' => $schema->string()
                ->description('The detail: what was said, what is expected, and any links or references.'),
            'type' => $schema->string()
                ->description('What kind of work this is.')
                ->enum(array_map(fn (TaskType $type): string => $type->value, TaskType::cases())),
            'priority' => $schema->string()
                ->description('How urgent it is. Default to medium unless the person said otherwise.')
                ->enum(array_map(fn (TaskPriority $priority): string => $priority->value, TaskPriority::cases())),
            'assignee' => $schema->string()
                ->description('Optional: who to assign it to, as @username or their name. They must already be a member of the project.'),
        ];
    }

    /**
     * What the person is being asked to agree to.
     *
     * Spelled out rather than left to a generic "the assistant wants to run
     * CreateTask", because the whole value of the pause is that the person can
     * see the model got the project and the title right before it becomes a card
     * three colleagues get notified about.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $title = $this->term($this->arg($request, 'title'), 120);
        $named = $this->arg($request, 'project');

        /*
         * Resolved rather than echoed. The model may have been given a name and
         * this card is the person's only chance to notice it picked the wrong
         * board — "Create X on Marketing" is checkable, "Create X on marketing
         * stuff" is the model's own words handed back.
         */
        $project = $this->project($named);

        $where = $project !== null
            ? "{$project->name} ({$project->key})"
            : (is_string($named) && $named !== '' ? strtoupper($named) : 'a project');

        $assignee = $this->assigneeName($project, $this->arg($request, 'assignee'));

        return Approval::required(sprintf(
            'Create “%s” on %s%s.',
            $title,
            $where,
            $assignee === null ? '' : ", assigned to {$assignee}",
        ));
    }

    /**
     * The projects this person could have meant, when what they said matched
     * nothing.
     *
     * Answered with the list rather than with "not found", because the model's
     * next move should be to ask which one — and it cannot ask sensibly without
     * knowing the options. Capped, because a workspace with sixty boards would
     * otherwise spend the reply on a directory nobody reads.
     */
    private function noSuchProject(mixed $named): string
    {
        $name = is_string($named) ? trim($named) : '';

        if ($this->context->isGuest()) {
            return 'This person is a guest and cannot file tasks on projects.';
        }

        $projects = $this->visibleProjects()->orderBy('name')->limit(15)->get();

        if ($projects->isEmpty()) {
            return 'There are no projects in this workspace to file anything on.';
        }

        $options = $projects
            ->map(fn (Project $project): string => "{$project->key} · {$project->name}")
            ->implode("\n");

        return sprintf(
            "No project here is called [%s]. Ask which of these they meant — do not guess:\n%s",
            $name === '' ? '(nothing)' : $name,
            $options,
        );
    }

    /**
     * The project behind whatever the model called it.
     *
     * Three passes, narrowest first, because the three are not equally
     * trustworthy: a key is an identifier and an exact name is unambiguous,
     * while a partial match is a guess. The guess is only accepted when it
     * matches exactly one board — two candidates means the model does not know
     * which the person meant, and neither do we, so it falls through to
     * {@see self::noSuchProject()} and asks.
     *
     * Re-resolved within the workspace, like every identifier that came from
     * outside: a name is a short string a model can easily invent, and one
     * naming another tenant's project must find nothing rather than reach it.
     */
    private function project(mixed $named): ?Project
    {
        if (! is_string($named) || trim($named) === '') {
            return null;
        }

        if ($this->context->isGuest()) {
            return null;
        }

        $name = trim($named);

        $exact = $this->visibleProjects()
            ->where(fn (Builder $query) => $query
                ->where('key', 'ilike', $name)
                ->orWhere('name', 'ilike', $name))
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        $partial = $this->visibleProjects()
            ->where('name', 'ilike', '%'.$name.'%')
            ->limit(2)
            ->get();

        return $partial->count() === 1 ? $partial->first() : null;
    }

    /**
     * The boards this person could file on.
     *
     * Archived ones are excluded: a board nobody works from is not somewhere to
     * put new work, and {@see App\Policies\TaskPolicy} would refuse it anyway —
     * further down, with a message about permissions rather than about the board
     * being closed.
     *
     * @return Builder<Project>
     */
    private function visibleProjects(): Builder
    {
        return Project::query()
            ->where('workspace_id', $this->context->workspace->id)
            ->notArchived();
    }

    /**
     * Who to assign it to, and what to tell the model about that.
     *
     * # Why a silent drop was wrong
     *
     * This used to return an id or null, and null covered two completely
     * different situations: nobody was named, and the person named is not on
     * this project. {@see SaveTask} drops an assignee who is not a member —
     * membership is the assignment gate throughout Snack, and this tool is not
     * the place to make an exception to it — so the second case filed an
     * unassigned card, said nothing, and left Bite free to reply "filed and
     * assigned to Bob". Bob never heard about it.
     *
     * So the task is still created, because capturing the work is what was
     * actually asked for, and the model is handed a sentence saying the
     * assignment did not happen and why. That is the honest shape: the thing
     * that could be done was done, and the part that could not is reported.
     *
     * @return array{id: int|null, note: string}
     */
    private function resolveAssignee(Project $project, mixed $named): array
    {
        $name = is_string($named) ? trim(ltrim(trim($named), '@')) : '';

        if ($name === '') {
            return ['id' => null, 'note' => ''];
        }

        $member = $this->matching($project->users(), $name);

        if ($member !== null) {
            return ['id' => $member->id, 'note' => "Assigned to {$member->name}."];
        }

        /*
         * Not on the project. Whether they are in the workspace at all decides
         * which of two quite different things the model should say — "add them
         * to the board first" or "there is nobody here by that name" — and
         * getting that wrong sends somebody to the wrong screen.
         */
        $colleague = $this->matching($this->context->workspace->activeUsers(), $name);

        $note = $colleague !== null
            ? "It could NOT be assigned to {$colleague->name}: they are not a member of {$project->name}. "
                .'Say so, and that somebody on the project has to add them first.'
            : "It could NOT be assigned: nobody in this workspace matches [{$name}]. Say so.";

        return ['id' => null, 'note' => $note];
    }

    /**
     * The name to print on the approval card, resolved the same way.
     *
     * Deliberately says the person's real name even when they turn out not to be
     * on the project: the card's job is to show what the model understood, so
     * that somebody can stop it before it files the thing.
     */
    private function assigneeName(?Project $project, mixed $named): ?string
    {
        $name = is_string($named) ? trim(ltrim(trim($named), '@')) : '';

        if ($name === '') {
            return null;
        }

        $person = $project === null
            ? null
            : $this->matching($project->users(), $name);

        $person ??= $this->matching($this->context->workspace->activeUsers(), $name);

        // Falls back to what the model typed, so a card for somebody who does
        // not exist still shows the name the person will recognise as wrong.
        return $person === null ? $name : $person->name;
    }

    /**
     * The one person in a relation whose handle or name matches.
     *
     * Username first and exactly, because that is an identifier: somebody typing
     * `@ada` means Ada and not "Adam Adams". The name pass is an exact match too
     * — a partial one would let "Chris" assign work to whichever Christopher the
     * database happened to return first, which is the kind of wrong that is only
     * noticed at standup.
     *
     * @param  BelongsToMany<User, *, *, *>  $people
     */
    private function matching(BelongsToMany $people, string $name): ?User
    {
        return (clone $people)
            ->where(fn (Builder $query) => $query
                ->where('users.username', 'ilike', $name)
                ->orWhere('users.name', 'ilike', $name))
            ->first();
    }

    private function description_(mixed $value): ?string
    {
        $description = is_string($value) ? trim($value) : '';

        return $description === '' ? null : mb_substr($description, 0, 5000);
    }

    private function type(mixed $value): string
    {
        $type = is_string($value) ? strtolower($value) : '';

        return (TaskType::tryFrom($type) ?? TaskType::Task)->value;
    }

    private function priority(mixed $value): string
    {
        $priority = is_string($value) ? strtolower($value) : '';

        return (TaskPriority::tryFrom($priority) ?? TaskPriority::Medium)->value;
    }
}
