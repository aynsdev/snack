<?php

namespace App\Ai;

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files;
use Throwable;

/**
 * The files somebody attached to the message they asked Bite about.
 *
 * # Why this is not `read_file`
 *
 * {@see App\Ai\Tools\ReadFile} is how Bite goes and *fetches* a file it heard
 * about: the model has a name, calls `find_files`, gets an id, and reads it. It
 * is a deliberate act that costs a step of the loop.
 *
 * This is the other direction. Somebody drags a screenshot into the composer
 * and types "@bite what is wrong here?" — there is no name to search for, no id
 * to look up, and the file is the entire question. Before this, the attachment
 * was invisible: the job passed only `$message->body`, so Bite answered a
 * question about a picture it had never been shown, which is the worst possible
 * failure mode for something people trust to read documents.
 *
 * # The three shapes, and why they differ
 *
 * Images go as bytes and documents as URLs the provider fetches itself — a
 * shape chosen when Snack ran on Mistral, which took nothing else, and one
 * Anthropic accepts as well. See the note on {@see BiteFiles} and `ReadFile`. So:
 *
 *  - **images** go inline from storage, which works on every disk;
 *  - **PDFs** go as a short-lived signed URL, and are simply named when the disk
 *    cannot issue one;
 *  - **text** is put into the prompt itself, bounded, because it is cheaper and
 *    more reliable than any attachment mechanism;
 *  - **everything else** is named and described, so Bite can say "you have sent
 *    me a zip file, I cannot open it" rather than inventing its contents.
 *
 * # Authorization
 *
 * These come off the message that summoned Bite, through its own relation —
 * never from an id the model supplied. The person who attached them is the
 * person asking, and the container they were posted into is the one Bite is
 * standing in, so there is nothing here to re-resolve. That is exactly why this
 * does not go through `BiteFiles`, whose whole job is distrusting ids that have
 * been through a language model.
 */
final readonly class BiteUploads
{
    /**
     * How many files from one message Bite will look at.
     *
     * A person dropping twelve screenshots into one message is not asking about
     * twelve screenshots, and every one of them is a full image in the prompt.
     * The rest are named rather than silently ignored.
     */
    private const MAX_FILES = 4;

    /**
     * Everything the provider should be handed for this message.
     *
     * @return array<int, object>
     */
    public function attachmentsFor(Message $message): array
    {
        $attachments = [];

        foreach ($this->files($message) as $file) {
            $prepared = match (true) {
                $file->isImage() && ! $file->isTextual() => $this->image($file),
                $file->isPdf() => $this->document($file),
                default => null,
            };

            if ($prepared !== null) {
                $attachments[] = $prepared;
            }
        }

        return $attachments;
    }

    /**
     * The part of the prompt that describes what was attached.
     *
     * Always written, even for files that went to the provider as attachments:
     * a model handed an image with no explanation will sometimes ignore it
     * entirely, and naming the file is also what lets Bite refer to it in its
     * answer the way the person would.
     *
     * Returns an empty string when the message carried nothing.
     */
    public function describe(Message $message): string
    {
        $files = $this->files($message);

        if ($files->isEmpty()) {
            return '';
        }

        $lines = $files->map(fn (MessageAttachment $file): string => $this->line($file))->implode("\n");

        $extra = $message->attachments->count() - $files->count();

        $note = $extra > 0
            ? "\n\n[{$extra} further file(s) were attached and not read. Say so if the answer might be in them.]"
            : '';

        return <<<TEXT


        # Files attached to this message

        The person attached these to the message you are answering. Where the
        contents are below, use them. Where a file is only named, you have not
        been shown what is inside it — say so rather than guessing.

        {$lines}{$note}
        TEXT;
    }

    /**
     * The attachments worth looking at, newest-first order preserved.
     *
     * @return Collection<int, MessageAttachment>
     */
    private function files(Message $message): Collection
    {
        if (! $message->relationLoaded('attachments')) {
            $message->load('attachments');
        }

        return $message->attachments->take(self::MAX_FILES)->values();
    }

    /**
     * One file's line in the prompt: its contents when they are text, its name
     * and type otherwise.
     */
    private function line(MessageAttachment $file): string
    {
        if ($file->isTextual()) {
            $content = $this->text($file);

            return $content === null
                ? "- [{$file->original_name}] — a text file that could not be read from storage."
                : "- [{$file->original_name}]:\n\n{$content}";
        }

        if ($file->isImage()) {
            return "- [{$file->original_name}] — an image, attached to this conversation for you to look at.";
        }

        if ($file->isPdf()) {
            return "- [{$file->original_name}] — a PDF, attached to this conversation for you to read.";
        }

        return "- [{$file->original_name}] — a {$file->mime_type} file. You have not been shown its contents.";
    }

    /**
     * A text file's contents, bounded and truncated loudly.
     *
     * The same budget `read_file` uses, because it is the same risk: a 40MB CSV
     * in the prompt is a context-window error rather than an answer.
     */
    private function text(MessageAttachment $file): ?string
    {
        $max = (int) config('snack.bite.files.max_bytes', 200_000);

        try {
            $content = Storage::disk($file->disk)->get($file->path);
        } catch (Throwable) {
            return null;
        }

        if ($content === null) {
            return null;
        }

        return mb_strlen($content) > $max
            ? mb_substr($content, 0, $max)."\n\n[Truncated — the file is longer than this.]"
            : $content;
    }

    private function image(MessageAttachment $file): ?object
    {
        try {
            return Files\Image::fromStorage($file->path, $file->disk);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * A PDF, as a link the provider fetches for itself.
     *
     * Null when the disk cannot sign one — a local disk without a configured
     * URL, typically. The file is still named in the prompt, so the answer is
     * "I can see you attached the invoice but cannot open it here" rather than
     * silence.
     */
    private function document(MessageAttachment $file): ?object
    {
        $minutes = (int) config('snack.bite.files.url_ttl_minutes', 5);

        try {
            $url = Storage::disk($file->disk)->temporaryUrl($file->path, now()->addMinutes($minutes));
        } catch (Throwable) {
            return null;
        }

        return Files\Document::fromUrl($url)->as($file->original_name);
    }
}
