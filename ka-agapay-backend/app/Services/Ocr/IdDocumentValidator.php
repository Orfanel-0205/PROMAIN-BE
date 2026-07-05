<?php
// app/Services/Ocr/IdDocumentValidator.php
//
// Validates that an uploaded file is a REAL government / employee ID image and
// not a meme, selfie, landscape photo, or receipt. It combines cheap, dependency
// -free signals:
//   • true MIME / image type (getimagesize — catches renamed files)
//   • file size
//   • image dimensions + portrait aspect ratio (Employee ID is portrait)
//   • required-keyword scoring against the OCR text already extracted
//   • duplicate-image detection via sha1 hash (optional column)
//
// It NEVER throws — every failure is reported as a structured reason so the
// caller (OcrController) can decide how to respond. All thresholds live in
// config/ocr_validation.php.

namespace App\Services\Ocr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IdDocumentValidator
{
    /**
     * @param  string  $fullPath   Absolute path to the stored upload.
     * @param  string  $ocrText    Text already extracted by the OCR provider.
     * @param  array{
     *     category?: string,        // 'employee_id' | 'resident_id'
     *     id_type?: string,
     *     mime?: string|null,
     *     size_kb?: float|int|null,
     *     user_id?: int|null,
     *     ocr_confidence?: float|int|null
     * }  $context
     * @return array{
     *     passed: bool,
     *     score: float,
     *     image_hash: ?string,
     *     checks: array<string, array{ok: bool, detail: string}>,
     *     reasons: string[]
     * }
     */
    public function validate(string $fullPath, string $ocrText, array $context = []): array
    {
        $config   = config('ocr_validation');
        $category = ($context['category'] ?? 'resident_id') === 'employee_id'
            ? 'employee_id'
            : 'resident_id';

        $checks  = [];
        $reasons = [];

        // Compute a stable hash up-front (used for duplicate detection + audit).
        $imageHash = is_file($fullPath) ? @sha1_file($fullPath) : null;

        // ── 1) File size ─────────────────────────────────────────────────────
        $sizeKb = $context['size_kb'] ?? (is_file($fullPath) ? filesize($fullPath) / 1024 : 0);
        $sizeOk = $sizeKb > 0 && $sizeKb <= $config['max_size_kb'];
        $checks['size'] = [
            'ok'     => $sizeOk,
            'detail' => sprintf('%.0f KB (max %d KB)', $sizeKb, $config['max_size_kb']),
        ];
        if (!$sizeOk) {
            $reasons[] = 'The file is too large. Please upload an image up to '
                . round($config['max_size_kb'] / 1024) . ' MB.';
        }

        // ── 2) True image type + dimensions ──────────────────────────────────
        $info = @getimagesize($fullPath);

        if ($info === false) {
            $checks['image'] = ['ok' => false, 'detail' => 'Not a readable image.'];
            $reasons[] = 'The uploaded file is not a valid photo. Please upload a clear JPG or PNG of your ID.';

            // No geometry possible — return early with what we have.
            return $this->result(false, 0.0, $imageHash, $checks, $reasons);
        }

        [$width, $height] = $info;
        $mime = $info['mime'] ?? ($context['mime'] ?? null);

        $mimeOk = in_array(strtolower((string) $mime), $config['allowed_mime'], true);
        $checks['mime'] = ['ok' => $mimeOk, 'detail' => (string) $mime];
        if (!$mimeOk) {
            $reasons[] = 'Only JPG, JPEG, and PNG images are accepted.';
        }

        $dimsOk = $width  >= $config['min_width']
            && $height >= $config['min_height']
            && $width  <= $config['max_width']
            && $height <= $config['max_height'];
        $checks['dimensions'] = [
            'ok'     => $dimsOk,
            'detail' => "{$width}x{$height}px",
        ];
        if (!$dimsOk) {
            $reasons[] = $width < $config['min_width'] || $height < $config['min_height']
                ? 'The image resolution is too low. Please take a clearer, closer photo of the whole ID.'
                : 'The image dimensions are unusual for an ID. Please upload a normal photo of the ID.';
        }

        // ── 3) Portrait aspect (Employee ID only) ────────────────────────────
        $portrait = $config['employee_portrait'];
        if ($category === 'employee_id' && $portrait['enforce'] && $height > 0) {
            $ratio = $width / $height;
            $portraitOk = $ratio >= $portrait['min_ratio'] && $ratio <= $portrait['max_ratio'];
            $checks['orientation'] = [
                'ok'     => $portraitOk,
                'detail' => 'ratio ' . round($ratio, 2) . ' (portrait expected)',
            ];
            if (!$portraitOk) {
                $reasons[] = 'An Employee ID should be photographed in portrait orientation, showing the full card.';
            }
        }

        // ── 4) Readable-text floor ───────────────────────────────────────────
        $text = trim($ocrText);
        $textOk = mb_strlen($text) >= $config['min_text_length'];
        $checks['text'] = [
            'ok'     => $textOk,
            'detail' => mb_strlen($text) . ' chars read',
        ];
        if (!$textOk) {
            $reasons[] = 'No readable ID text was detected. Make sure the photo is sharp, well-lit, and shows the printed text.';
        }

        // ── 5) Required-keyword score ────────────────────────────────────────
        $score = $this->keywordScore($text, $category, $config);
        $required = $config['min_keyword_score'][$category] ?? 3;
        $keywordsOk = $score >= $required;
        $checks['keywords'] = [
            'ok'     => $keywordsOk,
            'detail' => "score {$score} / required {$required}",
        ];
        if (!$keywordsOk) {
            $reasons[] = $category === 'employee_id'
                ? 'This does not look like a Malasiqui Employee ID. Please upload the official Employee Identification Card.'
                : 'This does not look like a valid government ID. Please upload a clear photo of a recognised ID.';
        }

        // ── 6) Duplicate image across accounts ───────────────────────────────
        if (
            $config['duplicate']['enforce']
            && $imageHash
            && Schema::hasTable('ocr_results')
            && Schema::hasColumn('ocr_results', 'image_hash')
        ) {
            $dupQuery = DB::table('ocr_results')->where('image_hash', $imageHash);
            if (!empty($context['user_id'])) {
                $dupQuery->where('user_id', '!=', $context['user_id']);
            }
            $isDuplicate = $dupQuery->exists();

            $checks['duplicate'] = [
                'ok'     => !$isDuplicate,
                'detail' => $isDuplicate ? 'Same image already used by another account.' : 'unique',
            ];
            if ($isDuplicate) {
                $reasons[] = 'This exact image has already been submitted for a different account.';
            }
        }

        // OPTIONAL FACE HOOK: real face detection needs OpenCV or a vision API.
        // If you later add one, set $checks['face'] here and append to $reasons.

        $passed = collect($checks)->every(fn ($c) => $c['ok'] === true);

        return $this->result($passed, (float) $score, $imageHash, $checks, $reasons);
    }

    /**
     * Weighted keyword score. Matching folds the OCR text down to lowercase
     * letters+digits (removing spaces/punctuation/newlines) so noisy reads like
     * "REPUBLIC  OF THE\nPHILIPPINES" still match "republic of the philippines".
     */
    private function keywordScore(string $text, string $category, array $config): int
    {
        $folded = preg_replace('/[^a-z0-9]/', '', strtolower($text)) ?? '';

        if ($folded === '') {
            return 0;
        }

        $groups = ['strong', 'id_fields'];
        if ($category === 'employee_id') {
            $groups[] = 'employee';
        }

        $score = 0;
        foreach ($groups as $group) {
            foreach (($config['keywords'][$group] ?? []) as $keyword => $weight) {
                $needle = preg_replace('/[^a-z0-9]/', '', strtolower($keyword)) ?? '';
                if ($needle !== '' && str_contains($folded, $needle)) {
                    $score += (int) $weight;
                }
            }
        }

        return $score;
    }

    private function result(bool $passed, float $score, ?string $hash, array $checks, array $reasons): array
    {
        return [
            'passed'     => $passed,
            'score'      => $score,
            'image_hash' => $hash,
            'checks'     => $checks,
            'reasons'    => array_values(array_unique($reasons)),
        ];
    }
}
