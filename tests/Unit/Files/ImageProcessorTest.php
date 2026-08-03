<?php

use App\Services\ImageProcessor;

/**
 * Orientation and animation: the two places where "what the file says" and
 * "what the reader sees" come apart.
 *
 * A unit test rather than a feature one — this class touches no database and no
 * disk, and building the fixture bytes is the whole difficulty.
 */
function processor(): ImageProcessor
{
    return new ImageProcessor;
}

/**
 * A JPEG whose pixel buffer is `$width` x `$height`, optionally carrying an EXIF
 * orientation tag.
 *
 * The tag is assembled by hand because that is what a phone actually writes and
 * what `getimagesize()` ignores: a minimal TIFF header with one IFD entry.
 */
function jpegWithOrientation(int $width, int $height, ?int $orientation = null): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 10, 120, 200));

    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);

    if ($orientation === null) {
        return $jpeg;
    }

    $tiff = 'II'.pack('vV', 42, 8);
    $entries = pack('v', 1)
        .pack('vvV', 0x0112, 3, 1).pack('vv', $orientation, 0)
        .pack('V', 0);

    $payload = "Exif\x00\x00".$tiff.$entries;
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    // After the SOI marker, where a decoder expects application segments.
    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

/**
 * A GIF89a with `$frames` frame blocks. One frame is a still picture.
 */
function gifWithFrames(int $frames): string
{
    $out = 'GIF89a'.pack('vv', 4, 6)."\xF7\x00\x00";

    for ($i = 0; $i < 256; $i++) {
        $out .= pack('CCC', $i, 0, 0);
    }

    for ($i = 0; $i < $frames; $i++) {
        $out .= "\x21\xF9\x04\x00\x0A\x00\x00\x00";
        $out .= "\x2C".pack('vvvv', 0, 0, 4, 6)."\x00";
        $out .= "\x08\x03\x01".chr($i + 1)."\x00";
    }

    return $out."\x3B";
}

describe('display dimensions', function () {
    it('reports the raw size when there is no orientation tag', function () {
        expect(processor()->dimensions(jpegWithOrientation(40, 20)))
            ->toBe(['width' => 40, 'height' => 20]);
    });

    it('transposes the edges for a quarter-turn orientation', function (int $orientation) {
        /*
         * The regression this exists for. A phone stores a portrait photo as a
         * landscape buffer plus "turn this", so getimagesize() says 40x20 for a
         * picture everybody would call 20x40. The thumbnail is rotated upright,
         * so reporting the raw size poured a portrait image into a landscape
         * box — which reads as the image having been rotated.
         */
        expect(processor()->dimensions(jpegWithOrientation(40, 20, $orientation)))
            ->toBe(['width' => 20, 'height' => 40]);
    })->with([5, 6, 7, 8]);

    it('leaves the edges alone for orientations that do not turn', function (int $orientation) {
        // 1 is upright, 2 mirrors, 3 is 180°, 4 mirrors vertically. None of
        // them swaps which edge is longer.
        expect(processor()->dimensions(jpegWithOrientation(40, 20, $orientation)))
            ->toBe(['width' => 40, 'height' => 20]);
    })->with([1, 2, 3, 4]);

    it('agrees with the orientation the thumbnail is written at', function () {
        $bytes = jpegWithOrientation(40, 20, 6);

        $reported = processor()->dimensions($bytes);
        $thumbnail = processor()->fit($bytes, 640);

        /*
         * The invariant, stated directly: whatever else these two disagree
         * about, they must agree on which edge is longer. When they did not,
         * the browser reserved a box of one shape for an image of the other.
         */
        expect($reported['width'] <=> $reported['height'])
            ->toBe($thumbnail->width <=> $thumbnail->height);
    });

    it('returns null for bytes that are not an image', function () {
        expect(processor()->dimensions('not an image at all'))->toBeNull();
    });
});

describe('animation', function () {
    it('spots a multi-frame gif', function () {
        expect(processor()->isAnimated(gifWithFrames(3)))->toBeTrue();
    });

    it('treats a single-frame gif as a still', function () {
        // A perfectly ordinary thing to be handed, and showing its thumbnail
        // costs nothing.
        expect(processor()->isAnimated(gifWithFrames(1)))->toBeFalse();
    });

    it('does not call a jpeg or png animated', function () {
        $png = (function () {
            $image = imagecreatetruecolor(4, 4);
            ob_start();
            imagepng($image);

            return (string) ob_get_clean();
        })();

        expect(processor()->isAnimated(jpegWithOrientation(10, 10)))->toBeFalse()
            ->and(processor()->isAnimated($png))->toBeFalse();
    });

    it('reads the webp animation flag rather than searching for a chunk name', function () {
        $header = 'RIFF'.pack('V', 0).'WEBP'.'VP8X'.pack('V', 10);

        $animated = $header.chr(0x02).str_repeat("\x00", 9);
        $still = $header.chr(0x00).str_repeat("\x00", 9);

        /*
         * The four bytes "ANIM" can appear inside compressed pixel data, so a
         * substring search would make still images claim to move.
         */
        expect(processor()->isAnimated($animated))->toBeTrue()
            ->and(processor()->isAnimated($still))->toBeFalse();
    });

    it('spots an apng only when acTL precedes the first frame', function () {
        $png = "\x89PNG\r\n\x1a\n";

        expect(processor()->isAnimated($png.'acTL'.'IDAT'))->toBeTrue()
            // A decoder ignores acTL after IDAT and renders a still; so does this.
            ->and(processor()->isAnimated($png.'IDAT'.'acTL'))->toBeFalse();
    });

    it('survives bytes that are not an image', function () {
        expect(processor()->isAnimated(''))->toBeFalse()
            ->and(processor()->isAnimated('nonsense'))->toBeFalse();
    });
});
