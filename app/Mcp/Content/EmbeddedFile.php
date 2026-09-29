<?php

namespace App\Mcp\Content;

use InvalidArgumentException;
use Laravel\Mcp\Server\Concerns\HasMeta;
use Laravel\Mcp\Server\Contracts\Content;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;

/**
 * A file handed back from a tool as an MCP embedded resource — the bytes,
 * Base64, with their MIME type.
 *
 * laravel/mcp offers text, image, and audio for tool results, but not a
 * document. The protocol does: a tool result may carry an `EmbeddedResource`,
 * which is how a PDF reaches a client able to read one. Clients that cannot
 * still get the text part of the same result, which names the file.
 */
class EmbeddedFile implements Content
{
    use HasMeta;

    public function __construct(
        protected string $bytes,
        protected string $mimeType,
        protected string $uri,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toTool(Tool $tool): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->mergeMeta([
            'type' => 'resource',
            'resource' => [
                'uri' => $this->uri,
                'mimeType' => $this->mimeType,
                'blob' => base64_encode($this->bytes),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPrompt(Prompt $prompt): array
    {
        throw new InvalidArgumentException('Embedded files are only returned from tools.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toResource(Resource $resource): array
    {
        throw new InvalidArgumentException('Embedded files are only returned from tools.');
    }

    public function __toString(): string
    {
        return $this->bytes;
    }
}
