<?php

namespace App\Ai\Tools;

use App\Ai\BiteContext;
use App\Ai\BiteMedia;
use App\Enums\MagnificModel;
use App\Enums\MediaKind;
use App\Models\BiteGeneration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * "@bite make a banner for the launch."
 *
 * Queues the image with {@see BiteMedia}, along with any reference image the
 * person attached; Bite posts it into the chat once
 * Magnific has made it. Only built when the installation has a Magnific key —
 * see {@see App\Ai\Agents\Bite::tools()}.
 */
class GenerateImage extends BiteTool implements Tool
{
    use DescribesModels;

    public function __construct(BiteContext $context, private readonly BiteMedia $media)
    {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return <<<TEXT
        Generate an image from a text description. The image is posted into this
        chat by you a little later, usually within a minute — so reply with a
        short line saying it is on its way, and do not describe the image as if
        you had seen it.

        Use this only when someone asks you to make, draw, design, or generate a
        picture. Write the prompt as a detailed visual description — subject,
        setting, style, lighting, colours — in English. One image per request
        unless asked for more.

        Reference images: an image attached to the person's message is sent to
        the model automatically as a reference. To follow an earlier image in
        this chat — including one you just made — set `use_previous_image`. To
        use a specific file, pass its id from `find_files`. Only models marked
        "text or image" can take a reference; with one, still describe in the
        prompt what to keep from it and what to change.

        Models (pass the id; use the default unless the person names one or the
        request clearly suits another):
        {$this->modelList(MediaKind::Image)}
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $prompt = $this->term($this->arg($request, 'prompt'), 2_000);

        if ($prompt === '') {
            return 'A prompt describing the image is required.';
        }

        $reference = $this->media->sourceImage(
            $this->context,
            $this->arg($request, 'file_id'),
            (bool) $this->arg($request, 'use_previous_image', false),
        );

        if (is_string($reference)) {
            return $reference;
        }

        $result = $this->media->queue(
            $this->context,
            MediaKind::Image,
            $this->arg($request, 'model'),
            $prompt,
            $this->arg($request, 'aspect_ratio'),
            source: $reference,
        );

        if (! $result instanceof BiteGeneration) {
            return $result;
        }

        $from = $reference !== null ? " using [{$reference->original_name}] as a reference" : '';

        return "Queued{$from} with {$result->model->label()}. It will be posted here when it is ready.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('A detailed visual description of the image.')
                ->required(),
            'model' => $schema->string()
                ->enum($this->modelIds(MediaKind::Image))
                ->description('Which model to use. Defaults to '.MagnificModel::defaultFor(MediaKind::Image)->value.'.'),
            'aspect_ratio' => $schema->string()
                ->enum(MagnificModel::ASPECT_RATIOS)
                ->description('1:1 unless the request implies a shape (banner → 16:9, story or phone wallpaper → 9:16).'),
            'use_previous_image' => $schema->boolean()
                ->description('Use the most recent image earlier in this chat as the reference.'),
            'file_id' => $schema->integer()
                ->description('A specific image to use as the reference, by the id find_files returned.'),
        ];
    }
}
