<?php

namespace App\Mcp\Tools;

use App\Ai\Tools\SearchTasks;
use App\Mcp\Tools\Concerns\ScopesAgentReads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Search the tasks on the workspace's project boards — the MCP face of
 * {@see SearchTasks}, run as the person who connected the client.
 */
#[Name('search_tasks')]
#[Title('Search Tasks')]
#[IsReadOnly]
class SearchTasksTool extends SnackTool
{
    use ScopesAgentReads;

    public function description(): string
    {
        return (string) static::describe(SearchTasks::class)->description();
    }

    public function handle(Request $request): Response
    {
        return $this->delegate(SearchTasks::class, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return static::describe(SearchTasks::class)->schema($schema);
    }
}
