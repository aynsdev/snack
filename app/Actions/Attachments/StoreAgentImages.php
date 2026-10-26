<?php

namespace App\Actions\Attachments;

use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Screenshots an agent connector attaches to its own thread post.
 *
 * The bytes arrive inline in an MCP call rather than through the composer's
 * upload endpoint, so this is the one place they are checked before they join
 * the same pipeline every web upload goes through ({@see StoreUpload}): same
 * disk, same keys, same unattached-until-claimed rows, same download policy.
 *
 * Only PNG and JPEG are accepted, and the declared type must match both the
 * file's leading bytes and what the server detects from its contents — an
 * agent cannot smuggle an SVG or an HTML page in under an image name.
 */
class StoreAgentImages
{
    public const MAX_IMAGES = 6;

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MIME_TYPES = ['image/png', 'image/jpeg'];

    private const SIGNATURES = [
        'image/png' => "\x89PNG\r\n\x1a\n",
        'image/jpeg' => "\xFF\xD8\xFF",
    ];

    public function __construct(private readonly StoreUpload $store) {}

    /**
     * Every image is decoded and checked before any is stored, so a bad one
     * leaves nothing behind.
     *
     * @param  list<array{filename: string, mime_type: string, data: string}>  $images
     * @return Collection<int, MessageAttachment>
     *
     * @throws InvalidArgumentException when an image is not an acceptable PNG or JPEG.
     */
    public function handle(Workspace $workspace, User $uploader, array $images): Collection
    {
        if (count($images) > self::MAX_IMAGES) {
            throw new InvalidArgumentException('At most '.self::MAX_IMAGES.' images can be attached to one message.');
        }

        $decoded = array_map(fn (array $image): array => $this->decode($image), $images);

        return collect($decoded)->map(
            fn (array $image): MessageAttachment => $this->store($workspace, $uploader, ...$image),
        )->values();
    }

    /**
     * @param  array{filename: string, mime_type: string, data: string}  $image
     * @return array{0: string, 1: string, 2: string}
     */
    private function decode(array $image): array
    {
        $mime = $image['mime_type'];
        $name = $image['filename'];

        if (! in_array($mime, self::MIME_TYPES, true)) {
            throw new InvalidArgumentException("{$name}: only PNG and JPEG images can be attached.");
        }

        if (strlen($image['data']) > intdiv(self::MAX_BYTES + 2, 3) * 4) {
            throw new InvalidArgumentException("{$name}: an image can be at most 5 MB.");
        }

        $bytes = base64_decode($image['data'], true);

        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException("{$name}: the image data is not valid base64.");
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException("{$name}: an image can be at most 5 MB.");
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! str_starts_with($bytes, self::SIGNATURES[$mime]) || $detected !== $mime) {
            throw new InvalidArgumentException("{$name}: the file is not a {$mime} image.");
        }

        return [$name, $mime, $bytes];
    }

    private function store(Workspace $workspace, User $uploader, string $name, string $mime, string $bytes): MessageAttachment
    {
        $path = tempnam(sys_get_temp_dir(), 'snack-agent-image-');

        if ($path === false) {
            throw new \RuntimeException('Could not stage the image for storage.');
        }

        try {
            file_put_contents($path, $bytes);

            return $this->store->handle($workspace, $uploader, new UploadedFile($path, $name, $mime, UPLOAD_ERR_OK, true));
        } finally {
            @unlink($path);
        }
    }
}
