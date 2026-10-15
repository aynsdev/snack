<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskLink;
use App\Support\TaskRelations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Everything on one card: the fields, the description, the discussion, and the
 * files — with file ids `read_file` takes, so an assistant can open the
 * screenshot attached to a bug report as well as read about it.
 */
#[Name('get_task')]
#[Title('Get Task')]
#[Description(<<<'TEXT'
Get one task in full: its status, type, priority, assignee, reporter, sprint,
release, parent, due date, labels, description, subtasks, web links (such as
pull requests), linked tasks, comments, and attached files. Pass the task
reference, such as ENG-42. Attached files are listed with a file id that
`read_file` takes.
TEXT)]
#[IsReadOnly]
class GetTaskTool extends SnackTool
{
    use ResolvesProjectWork;

    /**
     * Comments returned at most. A card with more than this is a meeting, and
     * the latest are the ones that say where it stands.
     */
    private const MAX_COMMENTS = 50;

    public function handle(Request $request): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $task = $this->task($context, $request->get('task'));

        if ($task === null) {
            return Response::error('There is no task with that reference that this person can see. References look like ENG-42.');
        }

        $task->load(['column', 'sprint', 'assignee', 'reporter', 'labels', 'epic', 'release', 'attachments']);

        $fields = array_filter([
            'Status' => $task->column->name,
            'Type' => $task->type->value,
            'Priority' => $task->priority->value,
            'Assignee' => $task->assignee !== null ? "{$task->assignee->name} (@{$task->assignee->username})" : 'unassigned',
            'Reporter' => $task->reporter?->name,
            'Sprint' => $task->sprint_id !== null ? $task->sprint->name : ($task->project->uses_sprints ? 'backlog' : null),
            'Parent' => $task->epic !== null ? $task->project->reference($task->epic->number).' '.$task->epic->title : null,
            'Release' => $task->release?->name,
            'Story points' => $task->story_points,
            'Due' => $task->due_on?->toFormattedDateString(),
            'Labels' => $task->labels->pluck('name')->implode(', ') ?: null,
            'Created' => $task->created_at?->toDayDateTimeString(),
            'Completed' => $task->completed_at?->toDayDateTimeString(),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $comments = $task->comments()
            ->with('author:id,name,username')
            ->latest('id')
            ->limit(self::MAX_COMMENTS)
            ->get()
            ->reverse()
            ->map(fn (TaskComment $comment): string => sprintf(
                "%s, %s:\n  %s",
                // By id: the author is nulled when their account is deleted.
                $comment->author_id !== null ? $comment->author->name : 'A deleted user',
                $comment->created_at?->toDayDateTimeString() ?? 'unknown time',
                str($comment->body)->trim()->replace("\n", "\n  "),
            ))
            ->implode("\n\n");

        $files = $task->attachments
            ->map(fn (MessageAttachment $file): string => "- file #{$file->id} {$file->original_name} ({$file->mime_type}, {$file->humanSize()})")
            ->implode("\n");

        $children = $task->children()
            ->with(['column:id,name', 'assignee:id,username'])
            ->orderBy('number')
            ->get();

        $subtasks = $children
            ->map(fn (Task $child): string => sprintf(
                '- %s — %s [%s · %s] %s',
                $task->project->reference($child->number),
                $child->title,
                $child->column->name,
                $child->type->value,
                $child->assignee !== null ? "@{$child->assignee->username}" : 'unassigned',
            ))
            ->implode("\n");

        $links = $task->links()->get()
            ->map(fn (TaskLink $link): string => "- {$link->label()} — {$link->url}")
            ->implode("\n");

        $related = collect(TaskRelations::for($task, $context->user))
            ->map(fn (array $relation): string => sprintf(
                '- %s %s — %s [%s]',
                $relation['wording'],
                $relation['task']->reference(),
                $relation['task']->title,
                $relation['task']->column->name,
            ))
            ->implode("\n");

        $subtaskHeading = sprintf(
            '%s (%d of %d done)',
            $task->type->isContainer() ? 'Child issues' : 'Subtasks',
            $children->filter(fn (Task $child): bool => $child->isComplete())->count(),
            $children->count(),
        );

        $sections = [
            "{$task->reference()} — {$task->title}",
            collect($fields)->map(fn (mixed $value, string $label): string => "{$label}: {$value}")->implode("\n"),
            "Description:\n".($task->description !== null && trim($task->description) !== '' ? trim($task->description) : '(none)'),
            $subtasks !== '' ? "{$subtaskHeading}:\n{$subtasks}" : null,
            $links !== '' ? "Links:\n{$links}" : null,
            $related !== '' ? "Linked tasks:\n{$related}" : null,
            $files !== '' ? "Files:\n{$files}" : null,
            "Comments:\n".($comments !== '' ? $comments : '(none)'),
        ];

        return Response::text(implode("\n\n", array_filter($sections)));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task reference, such as ENG-42.')->required(),
        ];
    }
}
