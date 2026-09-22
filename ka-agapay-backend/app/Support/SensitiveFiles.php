<?php
// app/Support/SensitiveFiles.php
//
// Where sensitive uploads live, and the only way the app reads them.
//
// WHY THIS EXISTS (2026-09-14)
// ----------------------------
// Resident ID photos, PhilHealth IDs, staff employee IDs, scanned paper
// prescriptions and generated prescription / lab-request PDFs were all stored on
// the "public" disk. That disk is web-served at /storage/..., so any of those
// files could be opened by anyone holding the link: no login, no expiry.
// Prescription PDFs were also named after the prescription number
// (RHU1-RX-2024-0001, ...), so they could be fetched simply by counting.
//
// They now go to the "private" disk (storage/app/private), which the web server
// never serves. The app hands them out only through logged-in routes that check
// who is asking. Reads look on the private disk first and fall back to the old
// public location, so files uploaded before this change keep working until
// `php artisan storage:privatize-sensitive` moves them.

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SensitiveFiles
{
    public const DISK = 'private';

    /** Where these files used to live. Read-only fallback until they are moved. */
    public const LEGACY_DISK = 'public';

    /**
     * Top-level folders on the legacy public disk that hold sensitive files.
     *
     * 'chat/attachments' joined this list on 2026-09-22. Team Chat
     * attachments were still being written to the public disk and served at
     * a guessable /storage/ URL with no login, which is the same fault this
     * class was written to close. Staff send each other wound photographs,
     * laboratory results and referral papers through that box.
     *
     * The entry is the attachments folder, NOT 'chat'. The sweep walks
     * everything beneath whatever it is given, and group avatars live in
     * chat/group-images, where they are meant to stay public. Listing the
     * parent dragged those private too and broke every group picture at
     * once -- twice, before the cause was understood.
     */
    public const SENSITIVE_DIRECTORIES = ['ocr', 'prescriptions', 'chat/attachments'];

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }

    /** Store an upload privately and return the relative path to save in the database. */
    public static function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, self::DISK);
    }

    public static function put(string $path, string $contents): void
    {
        self::disk()->put($path, $contents);
    }

    /** The disk currently holding $path (private first, then legacy public), or null. */
    public static function locate(?string $path): ?FilesystemAdapter
    {
        if (!$path) {
            return null;
        }

        if (self::disk()->exists($path)) {
            return self::disk();
        }

        $legacy = Storage::disk(self::LEGACY_DISK);

        return $legacy->exists($path) ? $legacy : null;
    }

    public static function exists(?string $path): bool
    {
        return self::locate($path) !== null;
    }

    /** Absolute path for server-side processing such as OCR, or null if missing. */
    public static function absolutePath(?string $path): ?string
    {
        return self::locate($path)?->path($path);
    }

    /** Delete from both locations. A missing file is not an error. */
    public static function delete(string $path): void
    {
        foreach ([self::DISK, self::LEGACY_DISK] as $name) {
            try {
                Storage::disk($name)->delete($path);
            } catch (\Throwable) {
                // Nothing there to delete is fine.
            }
        }
    }
}
