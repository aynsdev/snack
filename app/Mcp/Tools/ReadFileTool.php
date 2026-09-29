<?php

namespace App\Mcp\Tools;

use App\Ai\BiteFiles;
use App\Mcp\Content\FileResponse;
use App\Models\MessageAttachment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

/**
 * The contents of a file: an asset on the library's shelf, a file attached to
 * a task, or one shared in a conversation the person can read.
 *
 * Unlike Bite's ReadFile, which has to send documents to a sub-agent because
 * its tools may only return text, an MCP client is itself a model that can
 * read — so the file goes back as it is: text as text, an image as an image,
 * a PDF as an embedded resource. No second model, no Snack-side AI cost.
 *
 * Access is {@see BiteFiles::resolve()}, the same rule Bite uses, re-checked
 * against the token's owner on every call: a file id is text a model has been
 * handling, and is exactly as trustworthy as one typed into a URL.
 */
#[Name('read_file')]
#[Title('Read File')]
#[Description(<<<'TEXT'
Read a file's contents by its file id — from `find_files`, `browse_assets`,
`get_task`, or a message. Text files (Markdown, CSV, JSON, code, plain text)
come back as text, images as images, and PDFs as an attached document.
Other kinds of file cannot be read; their name, type, and size are returned.

Every result includes a download link. When the person asks for a file —
"give me the logo", "send me the contract" — give them that link.
TEXT)]
#[IsReadOnly]
class ReadFileTool extends SnackTool
{
    public function handle(Request $request, BiteFiles $files): Response|ResponseFactory
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $file = $files->resolve($context, $request->get('file_id'));

        if ($file === null) {
            return Response::error('There is no file with that id that this person can read. Use `find_files` or `browse_assets` to get a valid id.');
        }

        $summary = "[{$file->original_name}] {$file->mime_type}, {$file->humanSize()}\n"
            .'Download link (give this to the person when they want the file; it opens for them while signed in to Snack): '
            .$this->downloadUrl($file);

        if (! $file->isTextual() && ! $file->isImage() && ! $file->isPdf()) {
            return Response::text("{$summary}\n\nThis kind of file cannot be read here; describe it by its name and type.");
        }

        if ($file->size > (int) config('snack.mcp.max_file_bytes')) {
            return Response::text("{$summary}\n\nIt is too large to read here.");
        }

        $bytes = $this->bytes($file);

        if ($bytes === null) {
            return Response::error("[{$file->original_name}] could not be read from storage.");
        }

        if ($file->isTextual()) {
            return Response::text("Contents of {$summary}:\n\n{$bytes}");
        }

        return Response::make([
            Response::text($summary),
            $file->isImage()
                ? Response::image($bytes, $file->mime_type)
                : FileResponse::embedded($bytes, $file->mime_type, "snack://files/{$file->id}/".rawurlencode($file->original_name)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'file_id' => $schema->integer()
                ->description('The numeric file id.')
                ->required(),
        ];
    }

    private function bytes(MessageAttachment $file): ?string
    {
        try {
            return Storage::disk($file->disk)->get($file->path);
        } catch (Throwable) {
            return null;
        }
    }
}
