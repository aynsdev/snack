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
 * "@bite animate this" / "make a 5 second clip of a rocket launch".
 *
 * Queues the video with {@see BiteMedia}. Videos take minutes, so Bite answers
 * at once and the clip arrives as its own message — see
 * {@see App\Jobs\GenerateBiteMedia}.
 */
class GenerateVideo extends BiteTool implements Tool
{
    use DescribesModels;

    public function __construct(BiteContext $context, private readonly BiteMedia $media)
    {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return <<<TEXT
        Generate a short video, from a text description or by animating an image.
        Videos take a few minutes; you will post the result into this chat when it
        is done. Reply with a short line saying it is on its way.

        Use this only when someone asks for a video, clip, or animation.

        Starting from an image: an image attached to the person's message is used
        automatically. To animate an earlier image in this chat — including one
        you just made — set `use_previous_image`. To use a specific file, pass its
        id from `find_files`. Only models marked "text or image" can start from
        an image.

        Write the prompt as a description of the motion and scene, in English.

        Models (pass the id; use the default unless the person names one or the
        request clearly suits another):
        {$this->modelList(MediaKind::Video)}
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $prompt = $this->term($this->arg($request, 'prompt'), 2_000);

        if ($prompt === '') {
            return 'A prompt describing the video is required.';
        }

        $source = $this->media->sourceImage(
            $this->context,
            $this->arg($request, 'file_id'),
            (bool) $this->arg($request, 'use_previous_image', false),
        );

        if (is_string($source)) {
            return $source;
        }

        $result = $this->media->queue(
            $this->context,
            MediaKind::Video,
            $this->arg($request, 'model'),
            $prompt,
            $this->arg($request, 'aspect_ratio'),
            $this->arg($request, 'duration_seconds'),
            $source,
        );

        if (! $result instanceof BiteGeneration) {
            return $result;
        }

        $from = $source !== null ? " from [{$source->original_name}]" : '';

        return "Queued a {$result->duration}-second video{$from} with {$result->model->label()}. "
            .'It usually takes a few minutes and will be posted here when it is ready.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('What happens in the video: subject, motion, camera, mood.')
                ->required(),
            'model' => $schema->string()
                ->enum($this->modelIds(MediaKind::Video))
                ->description('Which model to use. Defaults to '.MagnificModel::defaultFor(MediaKind::Video)->value.'.'),
            'aspect_ratio' => $schema->string()
                ->enum(MagnificModel::ASPECT_RATIOS)
                ->description('16:9 unless the request implies otherwise (story, reel, phone → 9:16).'),
            'duration_seconds' => $schema->integer()
                ->description('Length in seconds. Rounded to the nearest length the model offers.'),
            'use_previous_image' => $schema->boolean()
                ->description('Animate the most recent image earlier in this chat.'),
            'file_id' => $schema->integer()
                ->description('A specific image to animate, by the id find_files returned.'),
        ];
    }
}
