<?php

namespace App\Ai\Tools;

use App\Ai\BiteContext;
use App\Ai\BiteFiles;
use App\Models\MessageAttachment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

use function Laravel\Ai\agent;

/**
 * "What does this file actually say?"
 *
 * Takes a file id from {@see FindFiles} and returns the content, so Bite can
 * answer about the inside of a document rather than only its name.
 *
 * # Three ways in, because the provider only accepts two kinds of attachment
 *
 * Mistral takes images inline and documents only as a URL it fetches itself;
 * it will not accept PDF bytes in the request body. And a tool may only return
 * a string — it cannot add an attachment to the conversation that called it.
 * So the reading happens here rather than in the outer prompt:
 *
 *   - Text of any sort (Markdown, CSV, JSON, source, plain text) is read
 *     straight off the disk and returned. No provider round trip at all, which
 *     makes it the fast and cheap path as well as the universal one.
 *   - A PDF is handed to a one-shot sub-agent as a temporary URL. That means
 *     minting a signed URL to a private object, which is why it is short-lived
 *     and why the tool refuses when the disk cannot mint one.
 *   - An image is handed to the same sub-agent inline, Base64, which works on
 *     any disk including a local one.
 *
 * The sub-agent gets the caller's question, so what comes back is the answer
 * rather than the whole document — which is the difference between reading a
 * 90-page contract and putting a 90-page contract into the context window.
 */
class ReadFile extends BiteTool implements Tool
{
    /**
     * Types worth reading as text.
     *
     * Matched on the whole MIME type or a prefix, because the useful set is not
     * `text/*`: JSON, CSV, and most source files are served as `application/*`
     * or as whatever the uploader's browser guessed.
     */
    private const TEXT_TYPES = [
        'text/',
        'application/json',
        'application/xml',
        'application/javascript',
        'application/x-yaml',
        'application/yaml',
        'application/sql',
        'application/csv',
        'image/svg+xml',
    ];

    public function __construct(BiteContext $context, private readonly BiteFiles $files)
    {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Read the contents of a file so you can answer questions about what is
        inside it. Takes a file id from `find_files`.

        Works on text files (Markdown, CSV, JSON, source code, plain text), PDFs,
        and images. Always pass the specific question you are trying to answer —
        for long documents only the relevant part comes back, so a vague question
        gets a vague answer.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $file = $this->files->resolve($this->context, $this->arg($request, 'file_id'));

        if ($file === null) {
            return 'There is no file with that id that this person can read. Use `find_files` to get a valid id.';
        }

        $question = $this->term($this->arg($request, 'question'), 500)
            ?: 'Summarize this file.';

        return match (true) {
            $this->isText($file) => $this->readText($file),
            $file->isPdf() => $this->readDocument($file, $question),
            $file->isImage() => $this->readImage($file, $question),
            default => "[{$file->original_name}] is a {$file->mime_type} file, which cannot be read as text. "
                .'Describe it to the person by name and type instead.',
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'file_id' => $schema->integer()
                ->description('The numeric file id returned by find_files.')
                ->required(),
            'question' => $schema->string()
                ->description('What you want to know from this file. Be specific.')
                ->required(),
        ];
    }

    /**
     * Read the bytes as text, truncating loudly rather than quietly.
     */
    private function readText(MessageAttachment $file): string
    {
        $max = (int) config('snack.bite.files.max_bytes', 200_000);

        try {
            $content = Storage::disk($file->disk)->get($file->path);
        } catch (Throwable) {
            $content = null;
        }

        if ($content === null) {
            return "[{$file->original_name}] could not be read from storage.";
        }

        $truncated = mb_strlen($content) > $max;

        if ($truncated) {
            $content = mb_substr($content, 0, $max);
        }

        return "Contents of [{$file->original_name}]:\n\n".$content
            .($truncated
                ? "\n\n[Truncated: the file is larger than this tool will read. Say so if the answer might be in the rest.]"
                : '');
    }

    /**
     * Hand a PDF to a sub-agent as a URL the provider fetches for itself.
     */
    private function readDocument(MessageAttachment $file, string $question): string
    {
        $url = $this->temporaryUrl($file);

        if ($url === null) {
            return "[{$file->original_name}] is a PDF, and this installation's file storage cannot issue "
                .'the temporary link needed to read one. Describe it by name instead.';
        }

        return $this->ask(
            $question,
            Files\Document::fromUrl($url)->as($file->original_name),
            $file,
        );
    }

    /**
     * Hand an image to a sub-agent inline, which works on any disk.
     */
    private function readImage(MessageAttachment $file, string $question): string
    {
        return $this->ask(
            $question,
            Files\Image::fromStorage($file->path, $file->disk),
            $file,
        );
    }

    /**
     * One question, one file, one answer.
     *
     * Anonymous rather than a class of its own: it has no tools, no memory, and
     * no configuration to speak of, and giving it a name would suggest it is
     * something a caller might want to reach for on its own.
     */
    private function ask(string $question, mixed $attachment, MessageAttachment $file): string
    {
        try {
            $response = agent(
                instructions: 'You extract information from a single attached document or image. '
                    .'Answer only from what the file actually contains. If the answer is not in it, '
                    .'say so plainly rather than guessing. Be specific and quote figures and names exactly.',
            )->prompt(
                "The file is named [{$file->original_name}]. {$question}",
                attachments: [$attachment],
            );

            return "From [{$file->original_name}]:\n\n".(string) $response;
        } catch (Throwable $exception) {
            report($exception);

            return "[{$file->original_name}] could not be read: {$exception->getMessage()}";
        }
    }

    /**
     * A short-lived link to a private object.
     *
     * Short-lived because it is a bearer credential to a file the permission
     * model otherwise protects with a re-authorizing controller on every hit —
     * it is handed to a third party and will end up in their logs. Long enough
     * for one fetch is all it needs to be.
     *
     * Local disks cannot sign URLs unless configured to, and a `Storage`
     * driver that cannot throws rather than returning null, so the failure is
     * caught and reported as "cannot" rather than as a crashed job.
     */
    private function temporaryUrl(MessageAttachment $file): ?string
    {
        $minutes = (int) config('snack.bite.files.url_ttl_minutes', 5);

        try {
            return Storage::disk($file->disk)->temporaryUrl($file->path, now()->addMinutes($minutes));
        } catch (Throwable) {
            return null;
        }
    }

    private function isText(MessageAttachment $file): bool
    {
        foreach (self::TEXT_TYPES as $type) {
            if (str_starts_with($file->mime_type, $type)) {
                return true;
            }
        }

        return false;
    }
}
