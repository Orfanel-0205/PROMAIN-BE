<?php
// app/Console/Commands/PrivatizeSensitiveFiles.php
//
// Moves sensitive files out of the web-served public disk.
//
// See App\Support\SensitiveFiles for why. New uploads already go to the private
// disk; this relocates everything stored before that change. Safe to run more
// than once, and safe to run on every deploy:
//
//   - a file that already has a private copy is treated as moved, and only the
//     public copy is removed (the private one is newer, e.g. a regenerated PDF)
//   - otherwise the file is copied, and the public copy is deleted only after
//     the private copy is confirmed present and the same size
//   - anything that cannot be verified is left where it is and reported
//
// Run with --dry-run first to see what would move.

namespace App\Console\Commands;

use App\Support\SensitiveFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PrivatizeSensitiveFiles extends Command
{
    protected $signature = 'storage:privatize-sensitive
        {--dry-run : List what would move, and move nothing.}';

    protected $description = 'Move ID photos and prescription PDFs from public (web-served) storage to private storage.';

    public function handle(): int
    {
        $public = Storage::disk(SensitiveFiles::LEGACY_DISK);
        $private = SensitiveFiles::disk();
        $dryRun = (bool) $this->option('dry-run');

        $counts = ['moved' => 0, 'already_private' => 0, 'failed' => 0];

        foreach (SensitiveFiles::SENSITIVE_DIRECTORIES as $directory) {
            foreach ($public->allFiles($directory) as $path) {
                if ($dryRun) {
                    $this->line("  would move  {$path}");
                    $counts['moved']++;
                    continue;
                }

                try {
                    if ($private->exists($path)) {
                        $public->delete($path);
                        $counts['already_private']++;
                        continue;
                    }

                    $private->writeStream($path, $public->readStream($path));

                    if ($private->exists($path) && $private->size($path) === $public->size($path)) {
                        $public->delete($path);
                        $counts['moved']++;
                    } else {
                        $this->error("  could not verify the private copy; left in place: {$path}");
                        $counts['failed']++;
                    }
                } catch (\Throwable $e) {
                    $this->error("  failed: {$path} ({$e->getMessage()})");
                    $counts['failed']++;
                }
            }

            if (!$dryRun && $public->exists($directory) && $public->allFiles($directory) === []) {
                $public->deleteDirectory($directory);
            }
        }

        $this->newLine();
        $this->table(
            [$dryRun ? 'would move' : 'moved', 'already private', 'failed'],
            [[$counts['moved'], $counts['already_private'], $counts['failed']]]
        );

        if ($dryRun) {
            $this->info('Dry run: nothing was moved. Run again without --dry-run to move these files.');
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
