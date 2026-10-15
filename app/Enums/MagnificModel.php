<?php

namespace App\Enums;

/**
 * The Magnific models Bite can generate with.
 *
 * # Why a curated list rather than every endpoint
 *
 * Magnific exposes dozens of models and almost every one spells its request
 * differently: `aspect_ratio` as `widescreen_16_9` or `16:9` or a pixel `size`,
 * `duration` as a string or an integer, the source image as `image`,
 * `first_frame_image`, or a URL that has to be publicly reachable. Each case
 * here is one whose request shape has been checked against the published
 * OpenAPI spec, and {@see self::payload()} is the one place that knows it.
 *
 * Models whose image input must be a public URL are left out: uploads here sit
 * on a private disk, and handing a model a signed link to a workspace's file is
 * not something to do quietly.
 *
 * # Canonical ratios
 *
 * Bite and the people asking speak in plain ratios — `16:9`, `9:16`, `1:1`. Each
 * model maps those onto its own vocabulary and falls back to the nearest shape
 * it supports, so asking a 16:9-only video model for a square never fails.
 */
enum MagnificModel: string
{
    case Mystic = 'mystic';
    case Flux2Klein = 'flux-2-klein';
    case FluxPro11 = 'flux-pro-1.1';
    case Hyperflux = 'hyperflux';
    case Seedream45 = 'seedream-4.5';
    case Seedream5Pro = 'seedream-5-pro';
    case Recraft4 = 'recraft-4';
    case GptImage2 = 'gpt-image-2';
    case NanoBananaPro = 'nano-banana-pro';

    case Kling26Pro = 'kling-2.6-pro';
    case Kling3Turbo = 'kling-3-turbo';
    case Hailuo23 = 'hailuo-2.3';
    case SeedancePro = 'seedance-pro';
    case Wan26 = 'wan-2.6';
    case Ltx2Pro = 'ltx-2-pro';
    case Pixverse55 = 'pixverse-5.5';
    case Veo31 = 'veo-3.1';
    case Veo31Fast = 'veo-3.1-fast';

    /**
     * The plain ratios Bite may ask for.
     *
     * @var array<int, string>
     */
    public const ASPECT_RATIOS = ['1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3'];

    /**
     * Magnific's own names for the plain ratios, where a model uses them.
     */
    private const NAMED_RATIOS = [
        '1:1' => 'square_1_1',
        '16:9' => 'widescreen_16_9',
        '9:16' => 'social_story_9_16',
        '4:3' => 'classic_4_3',
        '3:4' => 'traditional_3_4',
        '3:2' => 'standard_3_2',
        '2:3' => 'portrait_2_3',
    ];

    public static function defaultFor(MediaKind $kind): self
    {
        return $kind === MediaKind::Image ? self::Mystic : self::Kling26Pro;
    }

    /**
     * @return array<int, self>
     */
    public static function forKind(MediaKind $kind): array
    {
        return array_values(array_filter(self::cases(), fn (self $model): bool => $model->kind() === $kind));
    }

    public function kind(): MediaKind
    {
        return match ($this) {
            self::Mystic, self::Flux2Klein, self::FluxPro11, self::Hyperflux, self::Seedream45,
            self::Seedream5Pro, self::Recraft4, self::GptImage2, self::NanoBananaPro => MediaKind::Image,
            default => MediaKind::Video,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Mystic => 'Mystic',
            self::Flux2Klein => 'Flux 2 Klein',
            self::FluxPro11 => 'Flux Pro 1.1',
            self::Hyperflux => 'HyperFlux',
            self::Seedream45 => 'Seedream 4.5',
            self::Seedream5Pro => 'Seedream 5 Pro',
            self::Recraft4 => 'Recraft 4',
            self::GptImage2 => 'GPT Image 2',
            self::NanoBananaPro => 'Nano Banana Pro',
            self::Kling26Pro => 'Kling 2.6 Pro',
            self::Kling3Turbo => 'Kling 3 Turbo',
            self::Hailuo23 => 'Hailuo 2.3',
            self::SeedancePro => 'Seedance Pro',
            self::Wan26 => 'WAN 2.6',
            self::Ltx2Pro => 'LTX 2 Pro',
            self::Pixverse55 => 'PixVerse 5.5',
            self::Veo31 => 'Veo 3.1',
            self::Veo31Fast => 'Veo 3.1 Fast',
        };
    }

    /**
     * One line for the model's tool description: what it is good at.
     */
    public function summary(): string
    {
        return match ($this) {
            self::Mystic => 'photorealistic, Magnific\'s own (default)',
            self::Flux2Klein => 'very fast',
            self::FluxPro11 => 'high quality, good prompt following',
            self::Hyperflux => 'fastest, cheapest',
            self::Seedream45 => 'fast, high quality',
            self::Seedream5Pro => 'newest Seedream, high detail',
            self::Recraft4 => 'design, illustration, logos',
            self::GptImage2 => 'best with text inside the image',
            self::NanoBananaPro => 'Google, strong composition',
            self::Kling26Pro => 'text or image, with audio (default)',
            self::Kling3Turbo => 'text or image, 3–15 seconds',
            self::Hailuo23 => 'text or image, 6 seconds',
            self::SeedancePro => 'text or image, cinematic',
            self::Wan26 => 'text only, up to 15 seconds',
            self::Ltx2Pro => 'text only, with audio',
            self::Pixverse55 => 'text only, stylised',
            self::Veo31 => 'Google, text or image, with audio, premium',
            self::Veo31Fast => 'Google, text or image, cheaper',
        };
    }

    public function acceptsImage(): bool
    {
        return in_array($this, [
            self::Kling26Pro, self::Kling3Turbo, self::Hailuo23, self::SeedancePro, self::Veo31, self::Veo31Fast,
        ], true);
    }

    /**
     * Where the request is sent. Some models have one endpoint for text and
     * another for an image.
     */
    public function submitPath(bool $withImage): string
    {
        return match ($this) {
            self::Mystic => '/v1/ai/mystic',
            self::Flux2Klein => '/v1/ai/text-to-image/flux-2-klein',
            self::FluxPro11 => '/v1/ai/text-to-image/flux-pro-v1-1',
            self::Hyperflux => '/v1/ai/text-to-image/hyperflux',
            self::Seedream45 => '/v1/ai/text-to-image/seedream-v4-5',
            self::Seedream5Pro => '/v1/ai/text-to-image/seedream-v5-pro',
            self::Recraft4 => '/v1/ai/text-to-image/recraft-v4',
            self::GptImage2 => '/v1/ai/text-to-image/gpt-image-2',
            self::NanoBananaPro => '/v1/ai/text-to-image/nano-banana-pro',
            self::Kling26Pro => '/v1/ai/image-to-video/kling-v2-6-pro',
            self::Kling3Turbo => $withImage ? '/v1/ai/image-to-video/kling-v3-turbo-1080p' : '/v1/ai/text-to-video/kling-v3-turbo-1080p',
            self::Hailuo23 => '/v1/ai/image-to-video/minimax-hailuo-2-3-1080p',
            self::SeedancePro => '/v1/ai/image-to-video/seedance-pro-1080p',
            self::Wan26 => '/v1/ai/text-to-video/wan-v2-6-1080p',
            self::Ltx2Pro => '/v1/ai/text-to-video/ltx-2-pro',
            self::Pixverse55 => '/v1/ai/text-to-video/pixverse-v5-5',
            self::Veo31 => $withImage ? '/v1/ai/image-to-video/veo-3-1' : '/v1/ai/text-to-video/veo-3-1',
            self::Veo31Fast => $withImage ? '/v1/ai/image-to-video/veo-3-1-fast' : '/v1/ai/text-to-video/veo-3-1-fast',
        };
    }

    /**
     * Where the task is polled. Usually the submit path plus the id, except
     * where one status endpoint serves a family of models.
     */
    public function statusPath(bool $withImage, string $taskId): string
    {
        $base = match ($this) {
            self::Kling26Pro => '/v1/ai/image-to-video/kling-v2-6',
            self::Kling3Turbo => $withImage ? '/v1/ai/image-to-video/kling-v3-turbo' : '/v1/ai/text-to-video/kling-v3-turbo',
            default => $this->submitPath($withImage),
        };

        return $base.'/'.rawurlencode($taskId);
    }

    /**
     * The request body for this model.
     *
     * @return array<string, mixed>
     */
    public function payload(string $prompt, ?string $aspectRatio, ?int $duration, ?string $imageBase64): array
    {
        $body = ['prompt' => $prompt];

        if ($imageBase64 !== null && $this->acceptsImage()) {
            $body[$this === self::Hailuo23 ? 'first_frame_image' : 'image'] = $imageBase64;
        }

        $ratio = $this->ratioFor($aspectRatio);

        if ($ratio !== null) {
            $body[$this === self::Wan26 ? 'size' : 'aspect_ratio'] = $ratio;
        }

        if ($this->kind() === MediaKind::Video) {
            $seconds = $this->durationFor($duration);

            $body['duration'] = $this->durationIsString() ? (string) $seconds : $seconds;
        }

        return $body;
    }

    /**
     * The video lengths this model offers, in seconds.
     *
     * @return array<int, int>
     */
    public function durations(): array
    {
        return match ($this) {
            self::Kling26Pro, self::SeedancePro => [5, 10],
            self::Kling3Turbo => range(3, 15),
            self::Hailuo23 => [6],
            self::Wan26 => [5, 10, 15],
            self::Ltx2Pro => [6, 8, 10],
            self::Pixverse55 => [5, 8, 10],
            self::Veo31, self::Veo31Fast => [4, 6, 8],
            default => [],
        };
    }

    public function durationFor(?int $requested): int
    {
        $options = $this->durations();

        if ($options === []) {
            return 0;
        }

        if ($requested === null) {
            return $options[0];
        }

        usort($options, fn (int $a, int $b): int => abs($a - $requested) <=> abs($b - $requested));

        return $options[0];
    }

    private function durationIsString(): bool
    {
        return in_array($this, [self::Kling26Pro, self::Kling3Turbo, self::SeedancePro, self::Wan26], true);
    }

    /**
     * This model's spelling of a plain ratio, or the nearest one it supports.
     */
    private function ratioFor(?string $requested): ?string
    {
        $supported = match ($this) {
            self::Hailuo23, self::Ltx2Pro => [],
            self::Kling26Pro => ['16:9', '9:16', '1:1'],
            self::Kling3Turbo => ['16:9', '9:16', '1:1'],
            self::Veo31, self::Veo31Fast => ['16:9', '9:16'],
            self::Wan26, self::Pixverse55 => ['16:9', '9:16', '1:1', '4:3', '3:4'],
            self::SeedancePro => ['16:9', '9:16', '1:1', '4:3', '3:4'],
            self::Seedream45, self::Seedream5Pro => ['1:1', '16:9', '9:16', '2:3', '3:4', '3:2', '4:3'],
            default => self::ASPECT_RATIOS,
        };

        if ($supported === []) {
            return null;
        }

        $ratio = in_array($requested, $supported, true) ? $requested : $this->nearest((string) $requested, $supported);

        return match (true) {
            $this === self::Wan26 => match ($ratio) {
                '16:9' => '1920*1080',
                '9:16' => '1080*1920',
                '1:1' => '1440*1440',
                '4:3' => '1632*1248',
                default => '1248*1632',
            },
            in_array($this, [self::NanoBananaPro, self::Kling3Turbo, self::Veo31, self::Veo31Fast], true) => $ratio,
            default => self::NAMED_RATIOS[$ratio],
        };
    }

    /**
     * The supported ratio closest in shape to the one asked for — a portrait
     * request stays portrait even when the exact ratio is not on offer.
     *
     * @param  array<int, string>  $supported
     */
    private function nearest(string $requested, array $supported): string
    {
        $value = fn (string $ratio): float => (float) str($ratio)->before(':')->toString()
            / max(1.0, (float) str($ratio)->after(':')->toString());

        $target = str_contains($requested, ':') ? $value($requested) : ($this->kind() === MediaKind::Video ? 16 / 9 : 1.0);

        usort($supported, fn (string $a, string $b): int => abs(log($value($a) / $target)) <=> abs(log($value($b) / $target)));

        return $supported[0];
    }
}
