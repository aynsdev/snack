<?php

namespace App\Mcp\Tools;

use App\Ai\Tools\SearchMessages;
use App\Mcp\Tools\Concerns\ScopesAgentReads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Search everything said in the workspace's channels and direct messages — the MCP face of
 * {@see SearchMessages}, run as the person who connected the client.
 */
#[Name('search_messages')]
#[Title('Search Messages')]
#[IsReadOnly]
class SearchMessagesTool extends SnackTool
{
    use ScopesAgentReads;

    public function description(): string
    {
        return (string) static::describe(SearchMessages::class)->description();
    }

    public function handle(Request $request): Response
    {
        return $this->delegate(SearchMessages::class, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return static::describe(SearchMessages::class)->schema($schema);
    }
}
