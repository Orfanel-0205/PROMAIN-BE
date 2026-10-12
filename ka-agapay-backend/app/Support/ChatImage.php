<?php
// app/Support/ChatImage.php
//
// A PHOTO FOR THE ASSISTANT, MADE SMALL ENOUGH TO ANSWER QUICKLY.
//
// Phones send camera photos at full size: 3,000-4,000 pixels across and
// several megabytes. Gemini took more than 25 seconds to start answering one,
// twice in a row, and the resident got the generic "Nandito ako para
// tumulong…" instead of an answer (production, 9 Oct 2026, three times:
// "Operation timed out after 25002 milliseconds with 0 bytes received"). At
// 1,600 pixels a rash, a wound or a medicine label is just as readable and
// the photo is a few hundred kilobytes.
//
// Never fails a message: anything GD cannot read (HEIC, which Gemini accepts
// as is) or any error returns the original unchanged.

namespace App\Support;

final class ChatImage
{
    public const MAX_EDGE = 1600;

    private const QUALITY = 82;

    /**
     * @return array{bytes: string, mime: string}
     */
    public static function shrink(string $bytes, string $mime): array
    {
        $original = ['bytes' => $bytes, 'mime' => $mime];

        if (!in_array(strtolower($mime), ['image/jpeg', 'image/png', 'image/webp'], true) || !function_exists('imagecreatefromstring')) {
            return $original;
        }

        try {
            $image = @imagecreatefromstring($bytes);

            if ($image === false) {
                return $original;
            }

            $image = self::upright($image, $bytes, $mime);

            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, self::MAX_EDGE / max($width, $height));

            if ($scale < 1) {
                $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC);

                if ($resized !== false) {
                    imagedestroy($image);
                    $image = $resized;
                }
            }

            // A PNG screenshot with transparency would turn black as a JPEG;
            // paint it onto white first.
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            imagedestroy($image);

            ob_start();
            imagejpeg($flat, null, self::QUALITY);
            $jpeg = (string) ob_get_clean();
            imagedestroy($flat);

            // Already small and not resized: keep whichever is smaller.
            if ($jpeg === '' || ($scale >= 1 && strlen($jpeg) >= strlen($bytes))) {
                return $original;
            }

            return ['bytes' => $jpeg, 'mime' => 'image/jpeg'];
        } catch (\Throwable) {
            return $original;
        }
    }

    /** Turn a phone photo upright: the camera stores the rotation separately. */
    private static function upright(\GdImage $image, string $bytes, string $mime): \GdImage
    {
        if (strtolower($mime) !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }
}
