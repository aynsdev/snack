<?php

namespace App\Mcp\Tools;

use App\Ai\Tools\CreateTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * File a task on a project board — the MCP face of {@see CreateTask}, run as
 * the person who connected the client.
 *
 * Bite pauses this tool for an in-app approval card. Here there is no card to
 * show: the MCP client's own tool-permission prompt is the confirmation, which
 * is why this tool is annotated as a write and not read-only. Authorization is
 * unchanged — {@see CreateTask::handle()} checks the project policy against the
 * token's owner, and the owner is recorded as the reporter.
 */
#[Name('create_task')]
#[Title('Create Task')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class CreateTaskTool extends SnackTool
{
    public function description(): string
    {
        return (string) static::describe(CreateTask::class)->description();
    }

    public function handle(Request $request): Response
    {
        return $this->delegate(CreateTask::class, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return static::describe(CreateTask::class)->schema($schema);
    }
}
