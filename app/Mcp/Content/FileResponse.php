<?php

namespace App\Mcp\Content;

use Laravel\Mcp\Response;

/**
 * The one response laravel/mcp has no factory for: a file as an embedded
 * resource. A subclass because Response's constructor is protected.
 */
final class FileResponse extends Response
{
    public static function embedded(string $bytes, string $mimeType, string $uri): self
    {
        return new self(new EmbeddedFile($bytes, $mimeType, $uri));
    }
}
