<?php

namespace App\Services;

/**
 * A re-encoded image, ready to be written to a disk.
 *
 * Carries its own extension and MIME type because {@see ImageProcessor} decides
 * the output format from the source's transparency rather than from what was
 * uploaded — a PNG screenshot with no alpha comes back as a much smaller JPEG.
 */
final readonly class ProcessedImage
{
    public function __construct(
        public string $contents,
        public string $extension,
        public string $mimeType,
        public int $width,
        public int $height,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }
}
