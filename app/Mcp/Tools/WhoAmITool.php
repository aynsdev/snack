<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Who the assistant is working as.
 *
 * An MCP client is never told whose token it holds, so "my tasks", "what did
 * I say", or "mention me" all start from nothing. This is the answer, read
 * from the token itself — never from anything the client sent.
 */
#[Name('whoami')]
#[Title('Who Am I')]
#[Description(<<<'TEXT'
Get the person you are connected as: their name, @username, role, the
workspace, and the projects they are on. Use this whenever a request says
"me", "my", or "I" — for example to search `from:@username` for things they
said. For their tasks, `list_my_tasks` is quicker.
TEXT)]
#[IsReadOnly]
class WhoAmITool extends SnackTool
{
    public function handle(Request $request): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $user = $context->user;

        $projects = Project::query()
            ->where('workspace_id', $context->workspace->id)
            ->notArchived()
            ->whereMember($user)
            ->orderBy('name')
            ->get(['key', 'name'])
            ->map(fn (Project $project): string => "{$project->key} ({$project->name})")
            ->implode(', ');

        return Response::text(implode("\n", array_filter([
            "You are connected as {$user->name} (@{$user->username}).",
            $user->title ? "Title: {$user->title}" : null,
            $user->department ? "Department: {$user->department}" : null,
            "Workspace: {$context->workspace->name}, as {$context->role()?->label()}.",
            'Projects they are on: '.($projects !== '' ? $projects : 'none'),
            "Timezone: {$user->timezone}",
        ])));
    }
}
