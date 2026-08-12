<?php

namespace App\Ai\Tools;

use App\Actions\Projects\SaveTask;
use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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

        Before calling this you must:
        1. Get the project key from `browse_workspace`.
        2. Search with `search_tasks` to check the thing has not already been
           filed, and say so instead of filing a duplicate.

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
            return "No project with the key [{$key}] exists here. Use browse_workspace to get valid keys.";
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

        $task = app(SaveTask::class)->create(
            project: $project,
            reporter: $this->context->user,
            attributes: [
                'title' => $this->term($this->arg($request, 'title'), 200) ?: 'Untitled',
                'description' => $this->description_($this->arg($request, 'description')),
                'type' => $this->type($this->arg($request, 'type')),
                'priority' => $this->priority($this->arg($request, 'priority')),
                'assignee_id' => $this->assignee($project, $this->arg($request, 'assignee')),
            ],
        );

        return "Created {$task->reference()} — {$task->title}. "
            .'Tell the person it is filed, using that reference.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('The key of the project to file this on, for example ENG.')
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
                ->description('Optional: the username to assign it to. They must already be on the project.'),
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
        $key = $this->arg($request, 'project');

        return Approval::required(sprintf(
            'Create “%s” on %s.',
            $title,
            is_string($key) && $key !== '' ? strtoupper($key) : 'a project',
        ));
    }

    /**
     * The project behind a key the model supplied.
     *
     * Re-resolved within the workspace, like every other identifier that came
     * from outside: a key is a short string a model can easily invent, and one
     * naming another tenant's project must find nothing rather than reach it.
     */
    private function project(mixed $key): ?Project
    {
        if (! is_string($key) || $key === '') {
            return null;
        }

        if ($this->context->isGuest()) {
            return null;
        }

        return Project::query()
            ->where('workspace_id', $this->context->workspace->id)
            ->where('key', 'ilike', $key)
            ->first();
    }

    /**
     * The assignee, if they are somebody this project can actually be assigned to.
     *
     * Dropped rather than rejected when they are not: {@see SaveTask} re-resolves
     * this against the project's members anyway and would drop it there, so
     * failing here would only mean the same card gets filed with a worse error
     * message. Bite is told nothing about the drop, deliberately — the card that
     * comes back names the assignee it actually has.
     */
    private function assignee(Project $project, mixed $username): ?int
    {
        if (! is_string($username) || $username === '') {
            return null;
        }

        return $project->users()
            ->where('username', 'ilike', ltrim($username, '@'))
            ->value('users.id');
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
