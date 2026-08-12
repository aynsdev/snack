<?php

namespace App\Ai\Tools;

use App\Ai\BiteAttachments;
use App\Ai\BiteContext;
use App\Ai\BiteFiles;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * "Just send me the file."
 *
 * Puts a copy of a file the asker may already read onto the message Bite is
 * about to post, so the answer to "where's the brand guide?" is the brand guide
 * rather than directions to it.
 *
 * The copying, and why it is a copy, is {@see BiteAttachments}. What matters
 * here is that the id is re-resolved against the asker first — see
 * {@see BiteFiles} — so this cannot be used to lift a file out of a channel the
 * asker was never in by naming its id.
 *
 * Note that the file lands in the container Bite was addressed in, which may be
 * a channel with an audience wider than one person. That is not a leak — the
 * asker could have downloaded and re-posted it themselves, and Bite only ever
 * reaches files they can already read — but it is the reason the instructions
 * tell Bite to attach when asked rather than whenever it feels helpful.
 */
class AttachFile extends BiteTool implements Tool
{
    public function __construct(
        BiteContext $context,
        private readonly BiteFiles $files,
        private readonly BiteAttachments $attachments,
        private readonly User $bot,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Attach a copy of a file to your reply, so the person gets the actual file
        rather than a description of it. Takes a file id from `find_files`.

        Use this when someone asks you to send, share, or give them a file. Do
        not attach files they did not ask for — the reply may be going to a whole
        channel.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $file = $this->files->resolve($this->context, $this->arg($request, 'file_id'));

        if ($file === null) {
            return 'There is no file with that id that this person can read. Use `find_files` to get a valid id.';
        }

        $copy = $this->attachments->add($file, $this->bot);

        if ($copy === null) {
            return "[{$file->original_name}] could not be copied from storage, so it cannot be attached. "
                .'Tell the person the file exists but could not be sent.';
        }

        return "[{$file->original_name}] will be attached to your reply. Do not describe its contents "
            .'unless you have actually read it — mention it by name and let the attachment speak.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'file_id' => $schema->integer()
                ->description('The numeric file id returned by find_files.')
                ->required(),
        ];
    }
}
