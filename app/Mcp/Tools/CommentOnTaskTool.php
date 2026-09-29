<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Models\TaskComment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Add to the discussion on a card, under the person's own name — the same row
 * {@see App\Http\Controllers\Projects\TaskCommentController::store()} writes,
 * behind the same policy.
 */
#[Name('comment_on_task')]
#[Title('Comment on Task')]
#[Description(<<<'TEXT'
Post a comment on a task, under the name of the person who connected this
assistant. Pass the task reference, such as ENG-42. Only comment when the
person asked you to.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class CommentOnTaskTool extends SnackTool
{
    use ResolvesProjectWork;

    public function handle(Request $request): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $request->validate([
            'task' => ['required', 'string'],
            'body' => ['required', 'string', 'max:5000'],
        ], [
            'body.required' => 'A comment cannot be empty.',
            'body.max' => 'That comment is too long.',
        ]);

        $task = $this->task($context, $request->get('task'));

        if ($task === null) {
            return Response::error('There is no task with that reference that this person can see. References look like ENG-42.');
        }

        $allowed = Gate::forUser($context->user)->inspect('create', [TaskComment::class, $task]);

        if ($allowed->denied()) {
            return Response::error("This person cannot comment on {$task->reference()}: {$allowed->message()}");
        }

        $comment = TaskComment::create([
            'task_id' => $task->id,
            'author_id' => $context->user->id,
            'body' => trim((string) $request->get('body')),
        ]);

        return Response::text("Commented on {$task->reference()} (comment #{$comment->id}).");
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task reference, such as ENG-42.')->required(),
            'body' => $schema->string()->description('The comment text.')->required(),
        ];
    }
}
