<?php

namespace App\Services;

use GdImage;
use Throwable;

/**
 * Raster image work: thumbnails for attachments, square crops for avatars.
 *
 * Built on GD, which ships with the PHP this application runs on, rather than on
 * an image library — the two operations here are a scale and a centre crop, and
 * neither justifies a dependency.
 *
 * # Everything here is re-encoded, never passed through
 *
 * The output is always bytes this application wrote. That is what strips EXIF —
 * a photo taken on a phone carries the GPS coordinates of where it was taken,
 * and an avatar is visible to everyone who can read any message its owner has
 * ever posted. It also means a file that merely *claims* to be an image cannot
 * survive the round trip: if GD cannot decode it, there is no output at all.
 *
 * # Failure is a null, not an exception
 *
 * Callers are queued jobs and upload handlers, for which a corrupt or
 * unsupported image is an ordinary outcome rather than an error. A missing
 * thumbnail degrades to a file chip; a rejected avatar degrades to a validation
 * message. Neither should take down a worker.
 */
class ImageProcessor
{
    /**
     * MIME types GD can decode in this build. `gif` is included even though the
     * animation is lost: a still first frame is a better thumbnail than none.
     *
     * @var array<int, string>
     */
    private const DECODABLE = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
    ];

    public function canProcess(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), self::DECODABLE, true);
    }

    /**
     * Scale to fit inside a square box, preserving the aspect ratio.
     *
     * An image already smaller than the box is still re-encoded rather than
     * returned untouched — the point is as much the stripped metadata and the
     * known format as it is the pixel count.
     */
    public function fit(string $contents, int $maxEdge): ?ProcessedImage
    {
        $source = $this->decode($contents);

        if ($source === null) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, $maxEdge / max($width, $height));

        $target = $this->canvas(
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        );

        imagecopyresampled(
            $target, $source,
            0, 0, 0, 0,
            imagesx($target), imagesy($target), $width, $height,
        );

        imagedestroy($source);

        return $this->encode($target, $this->hasAlpha($contents));
    }

    /**
     * Centre-crop to an exact square, then scale to the requested size.
     *
     * Cropping before scaling is what makes an avatar of a wide group photo show
     * the middle of it rather than a letterboxed strip: every avatar in the app
     * is drawn in a circle, so the source has to *be* square before it gets
     * there or the browser will squash it into one.
     */
    public function square(string $contents, int $size): ?ProcessedImage
    {
        $source = $this->decode($contents);

        if ($source === null) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $edge = min($width, $height);

        // Never upscale: a 64px source becomes a 64px avatar rather than a
        // blurry 512px one.
        $side = min($size, $edge);
        $target = $this->canvas($side, $side);

        imagecopyresampled(
            $target, $source,
            0, 0,
            (int) (($width - $edge) / 2),
            (int) (($height - $edge) / 2),
            $side, $side, $edge, $edge,
        );

        imagedestroy($source);

        return $this->encode($target, $this->hasAlpha($contents));
    }

    /**
     * The pixel dimensions of an image, without decoding all of it.
     *
     * @return array{width: int, height: int}|null
     */
    public function dimensions(string $contents): ?array
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false) {
            return null;
        }

        return ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    private function decode(string $contents): ?GdImage
    {
        try {
            $image = @imagecreatefromstring($contents);
        } catch (Throwable) {
            return null;
        }

        if ($image === false) {
            return null;
        }

        return $this->applyExifOrientation($image, $contents);
    }

    /**
     * Rotate to the orientation the camera recorded.
     *
     * Phones store a landscape sensor readout plus an "actually, turn this"
     * flag. GD ignores the flag, so a portrait photo decodes on its side — and
     * once it is cropped and re-encoded, the flag is gone and the sideways
     * version is the only version left.
     */
    private function applyExifOrientation(GdImage $image, string $contents): GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($contents, "\xFF\xD8")) {
            return $image;
        }

        try {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contents));
        } catch (Throwable) {
            return $image;
        }

        $orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

        $rotation = match ((int) $orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($rotation === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $rotation, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * A transparent canvas with alpha blending set up for the copy that follows.
     */
    private function canvas(int $width, int $height): GdImage
    {
        // GD refuses a zero dimension, which a scale factor can round down to.
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

        // False only when the palette is exhausted, which cannot happen on a
        // truecolor canvas — but an unfilled canvas is still a usable one, so
        // this degrades rather than throws.
        if ($transparent !== false) {
            imagefill($canvas, 0, 0, $transparent);
        }

        return $canvas;
    }

    /**
     * PNG when the source could carry transparency, JPEG otherwise.
     *
     * Flattening a transparent PNG onto JPEG's opaque canvas turns the see-
     * through parts black, which is the single most visible way to ruin a logo
     * or a screenshot with rounded corners.
     */
    private function encode(GdImage $image, bool $preserveAlpha): ?ProcessedImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        ob_start();

        $encoded = $preserveAlpha
            ? imagepng($image, null, 6)
            : $this->encodeJpeg($image);

        $contents = (string) ob_get_clean();

        imagedestroy($image);

        if (! $encoded || $contents === '') {
            return null;
        }

        return new ProcessedImage(
            contents: $contents,
            extension: $preserveAlpha ? 'png' : 'jpg',
            mimeType: $preserveAlpha ? 'image/png' : 'image/jpeg',
            width: $width,
            height: $height,
        );
    }

    /**
     * JPEG has no alpha channel, so the transparent canvas is composited onto
     * white first. Without this the encoder reads the alpha bytes as colour.
     */
    private function encodeJpeg(GdImage $image): bool
    {
        $flattened = imagecreatetruecolor(imagesx($image), imagesy($image));

        imagefilledrectangle(
            $flattened, 0, 0, imagesx($image), imagesy($image),
            (int) imagecolorallocate($flattened, 255, 255, 255),
        );

        imagealphablending($flattened, true);
        imagecopy($flattened, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        $result = imagejpeg($flattened, null, 82);

        imagedestroy($flattened);

        return $result;
    }

    /**
     * Whether the *source* format can carry transparency, read from its magic
     * bytes rather than from the client's declared MIME type.
     */
    private function hasAlpha(string $contents): bool
    {
        return str_starts_with($contents, "\x89PNG")
            || str_starts_with($contents, 'GIF8')
            || (str_starts_with($contents, 'RIFF') && substr($contents, 8, 4) === 'WEBP');
    }
}
