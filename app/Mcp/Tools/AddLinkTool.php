<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\LinkTask;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Put a web link on a card — a pull request, most often — through the same
 * action the task panel uses, gated on TaskPolicy::update.
 */
#[Name('add_link')]
#[Title('Add Link')]
#[Description(<<<'TEXT'
Attach a web link to a task, such as a pull request, design, or incident.
Pass the task reference (ENG-42), the URL, and a short title such as
"PR #123: Fix fare rounding". Adding a URL the task already has updates its
title. Only add links when the person asked you to.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class AddLinkTool extends SnackTool
{
    use ResolvesProjectWork;

    public function handle(Request $request, LinkTask $linkTask): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $request->validate([
            'task' => ['required', 'string'],
            'url' => ['required', 'string'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $task = $this->task($context, $request->get('task'));

        if ($task === null) {
            return Response::error('There is no task with that reference that this person can see. References look like ENG-42.');
        }

        $allowed = Gate::forUser($context->user)->inspect('update', $task);

        if ($allowed->denied()) {
            return Response::error("This person cannot change {$task->reference()}: {$allowed->message()}");
        }

        try {
            $link = $linkTask->addLink($task, $context->user, (string) $request->get('url'), $request->get('title'));
        } catch (ValidationException $exception) {
            return Response::error($exception->validator->errors()->first());
        }

        return Response::text("Linked {$task->reference()} to {$link->label()} ({$link->url}).");
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task reference, such as ENG-42.')->required(),
            'url' => $schema->string()->description('The web address, starting with https://.')->required(),
            'title' => $schema->string()->description('A short title, such as "PR #123: Fix fare rounding".'),
        ];
    }
}
