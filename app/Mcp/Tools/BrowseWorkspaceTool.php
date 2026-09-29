<?php

namespace App\Mcp\Tools;

use App\Ai\Tools\BrowseWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * List the workspace's channels, people, or projects — the MCP face of
 * {@see BrowseWorkspace}, run as the person who connected the client.
 */
#[Name('browse_workspace')]
#[Title('Browse Workspace')]
#[IsReadOnly]
class BrowseWorkspaceTool extends SnackTool
{
    public function description(): string
    {
        return (string) static::describe(BrowseWorkspace::class)->description();
    }

    public function handle(Request $request): Response
    {
        return $this->delegate(BrowseWorkspace::class, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return static::describe(BrowseWorkspace::class)->schema($schema);
    }
}
