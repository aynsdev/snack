<?php

namespace App\Mcp\Tools;

use App\Ai\Tools\FindFiles;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Find files in the asset library and in message attachments — the MCP face
 * of {@see FindFiles}, run as the person who connected the client.
 */
#[Name('find_files')]
#[Title('Find Files')]
#[IsReadOnly]
class FindFilesTool extends SnackTool
{
    public function description(): string
    {
        return static::describe(FindFiles::class)->description()
            ."\n\nTo give the person a file they asked for, call `read_file` with its id: the result includes a download link.";
    }

    public function handle(Request $request): Response
    {
        return $this->delegate(FindFiles::class, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return static::describe(FindFiles::class)->schema($schema);
    }
}
