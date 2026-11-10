<?php
// app/Support/StaffSignatures.php
//
// THE MHO'S AND DOCTORS' SIGNATURES on e-prescriptions, lab requests and the
// ITR.
//
// A signature is drawn on the screen or uploaded as a photo of one signed on
// paper, cleaned up here into a transparent PNG, and kept on the private
// disk. Each save is a new version and the previous one is revoked, never
// overwritten: a document shows the version that was in force when it was
// issued. So a doctor who adds a signature today does not sign months of
// prescriptions after the fact, and one who replaces it does not change what
// a patient already carried to the pharmacy.

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class StaffSignatures
{
    /**
     * Whoever's name prints as the prescriber or the requesting physician.
     * The Super Admin too (Nov 2026): it already issues prescriptions and
     * completes consultations, and the account handed over to the RHU is
     * used to show the MHO the doctor's side of the system.
     */
    public const SIGNING_ROLES = ['mho', 'doctor', 'super_admin', 'superadmin'];

    private const DIRECTORY = 'signatures';

    // A phone photo is 12 million pixels and every one is looked at below,
    // so the work is done on a copy no longer than this on its long side.
    private const WORK_SIZE = 1000;

    // The saved image fits in this box: about the size of a signature line
    // on an A4 page at print resolution.
    private const MAX_WIDTH = 600;
    private const MAX_HEIGHT = 200;

    // Fewer ink pixels than this is an empty pad or a stray tap.
    private const MIN_INK_PIXELS = 150;

    // How much darker than the paper a pixel must be to count as ink, in a
    // photo. Lower picks up a faint ballpoint; higher drops shadows and
    // paper grain. The person moves it with the slider under the preview.
    public const DEFAULT_STRICTNESS = 50;
    public const MIN_STRICTNESS = 20;
    public const MAX_STRICTNESS = 100;

    public static function canSign(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole(self::SIGNING_ROLES);
    }

    /** The signature in force now, or null. */
    public static function current(int $userId): ?object
    {
        return self::activeAt($userId, now());
    }

    /** The signature that was in force at a moment, or null. */
    public static function activeAt(int $userId, Carbon $moment): ?object
    {
        if ($userId <= 0 || !Schema::hasTable('staff_signatures')) {
            return null;
        }

        return DB::table('staff_signatures')
            ->where('user_id', $userId)
            ->where('created_at', '<=', $moment)
            ->where(fn ($q) => $q->whereNull('revoked_at')->orWhere('revoked_at', '>', $moment))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The image to print on a document issued by $userId at $issuedAt, as a
     * data URI dompdf and the browser can both show, or null for a blank
     * signature line.
     */
    public static function forDocument(?int $userId, mixed $issuedAt): ?string
    {
        $userId = (int) ($userId ?? 0);

        if ($userId <= 0) {
            return null;
        }

        $moment = $issuedAt ? Carbon::parse($issuedAt) : now();

        return self::dataUri(self::activeAt($userId, $moment));
    }

    public static function dataUri(?object $row): ?string
    {
        $disk = $row ? SensitiveFiles::locate($row->path) : null;

        if (!$disk) {
            return null;
        }

        $bytes = $disk->get($row->path);

        return $bytes ? 'data:image/png;base64,' . base64_encode($bytes) : null;
    }

    /** Store a new version and revoke the one it replaces. */
    public static function save(User $user, string $imageBytes, string $source, int $strictness = self::DEFAULT_STRICTNESS): object
    {
        $png = self::normalize($imageBytes, $strictness);
        $path = self::DIRECTORY . '/' . $user->user_id . '/' . LocalTime::now()->format('YmdHis') . '-' . Str::random(8) . '.png';

        SensitiveFiles::put($path, $png);

        return DB::transaction(function () use ($user, $path, $png, $source) {
            $now = now();

            DB::table('staff_signatures')
                ->where('user_id', $user->user_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);

            $id = DB::table('staff_signatures')->insertGetId([
                'user_id' => $user->user_id,
                'path' => $path,
                'sha256' => hash('sha256', $png),
                'source' => $source,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table('staff_signatures')->find($id);
        });
    }

    /**
     * Stop signing new documents. Documents issued while it was in force keep
     * showing it, because they were signed then; the file is kept for them.
     */
    public static function revoke(User $user): bool
    {
        $now = now();

        return DB::table('staff_signatures')
            ->where('user_id', $user->user_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'updated_at' => $now]) > 0;
    }

    /**
     * Any picture of a signature in, a tight transparent PNG out.
     *
     * A drawn signature arrives as dark strokes on a transparent background
     * and keeps its pixels. A photo of one signed on paper arrives on an
     * opaque, often grey and unevenly lit page: the paper is taken to be the
     * most common brightness, anything clearly darker is ink, and the rest is
     * made transparent so the signature sits on the PDF's line, not in a box.
     * $strictness is how much darker than the paper counts as ink.
     *
     * @throws InvalidArgumentException when it is not a picture or has no signature in it
     */
    public static function normalize(string $bytes, int $strictness = self::DEFAULT_STRICTNESS): string
    {
        $strictness = max(self::MIN_STRICTNESS, min(self::MAX_STRICTNESS, $strictness));

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new InvalidArgumentException('That file is not a picture that can be read. Use a PNG or JPG.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::WORK_SIZE / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $work = self::transparentCanvas($w, $h);
        imagecopyresampled($work, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        // Brightness of every visible pixel. Transparent pixels (a drawn
        // signature's background) are never ink and never paper.
        $histogram = array_fill(0, 256, 0);
        $visible = 0;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($work, $x, $y);

                if ((($rgba >> 24) & 0x7F) > 100) {
                    continue;
                }

                $histogram[self::luminance($rgba)]++;
                $visible++;
            }
        }

        // Mostly transparent: a drawing, where everything visible is ink.
        // Mostly opaque: a photo or scan, where ink is darker than the paper.
        $drawn = $visible < ($w * $h) / 2;
        $threshold = $drawn ? 230 : max(0, self::median($histogram, $visible) - $strictness);

        $out = self::transparentCanvas($w, $h);
        $ink = 0;
        [$left, $top, $right, $bottom] = [$w, $h, -1, -1];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($work, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;

                if ($alpha > 100 || self::luminance($rgba) >= $threshold) {
                    continue;
                }

                // A photo's ink is made fully opaque; a drawing keeps the
                // soft edges the pad gave its strokes.
                imagesetpixel($out, $x, $y, $drawn ? $rgba : ($rgba & 0x00FFFFFF));
                $ink++;
                $left = min($left, $x);
                $top = min($top, $y);
                $right = max($right, $x);
                $bottom = max($bottom, $y);
            }
        }


        if ($ink < self::MIN_INK_PIXELS) {
            throw new InvalidArgumentException('No signature was found in the picture. Sign again, darker or larger.');
        }

        $pad = 6;
        $left = max(0, $left - $pad);
        $top = max(0, $top - $pad);
        $cropW = min($w, $right + $pad + 1) - $left;
        $cropH = min($h, $bottom + $pad + 1) - $top;
        $fit = min(1, self::MAX_WIDTH / $cropW, self::MAX_HEIGHT / $cropH);
        $finalW = max(1, (int) round($cropW * $fit));
        $finalH = max(1, (int) round($cropH * $fit));

        $final = self::transparentCanvas($finalW, $finalH);
        imagecopyresampled($final, $out, 0, 0, $left, $top, $finalW, $finalH, $cropW, $cropH);

        ob_start();
        imagepng($final, null, 9);
        $png = (string) ob_get_clean();

        return $png;
    }

    private static function transparentCanvas(int $w, int $h): \GdImage
    {
        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($image, 255, 255, 255, 127));

        return $image;
    }

    private static function luminance(int $rgba): int
    {
        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;

        return (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
    }

    private static function median(array $histogram, int $count): int
    {
        $half = $count / 2;
        $seen = 0;

        foreach ($histogram as $value => $n) {
            $seen += $n;

            if ($seen >= $half) {
                return $value;
            }
        }

        return 255;
    }
}
