<?php
// app/Services/Ocr/OcrVerificationService.php
//
// ID-document verification for the registration-approval flow: OCR an uploaded
// ID, parse the fields out of it, and score how well they match what the user
// typed during registration.
//
// -----------------------------------------------------------------------------
// WHY THIS FILE LOOKED THE WAY IT DID
// -----------------------------------------------------------------------------
// It previously began with "// app/Services/Ocr/OcrVerificationService.php —
// processOcr() method" and NO <?php tag, and contained a bare method body with
// no namespace and no class. It had been pasted back as a snippet rather than
// as a file.
//
// The consequence was worse than a parse error. PHP treats everything before an
// opening tag as literal output, so the class did not exist AND requiring the
// file echoed 2,309 bytes of source into the response stream — corrupting any
// JSON body it was prepended to — before failing with "class not found".
//
// It also called four helpers that were never carried over with the snippet
// (extractTextFromImage, parseIdText, calculateNameMatch, calculateDateMatch),
// so restoring only the opening tag would still have fatalled on first use.
// Those four are implemented below.
//
// -----------------------------------------------------------------------------
// HOW THIS RELATES TO THE OCR THAT ACTUALLY RUNS TODAY
// -----------------------------------------------------------------------------
// This is NOT the live OCR path. PhilHealth-ID and employee-ID scanning run
// through OcrController, which calls OCR.space directly and writes ocr_results
// with the query builder. That path is unaffected by this file.
//
// This service belongs to an earlier verification_documents-based design that
// was superseded. Nothing currently constructs a VerificationDocument and
// nothing dispatches ProcessOcrVerification, which is exactly why a file that
// could not even parse went unnoticed: it was never reached. The tests in
// tests/Feature/Ocr/OcrVerificationServiceTest.php pin this file's validity so
// the same breakage cannot silently ship again.

namespace App\Services\Ocr;

use App\Models\OcrResult;
use App\Models\RegistrationApproval;
use App\Models\VerificationDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class OcrVerificationService
{
    /** Below this, the scanned ID is not treated as matching the registration. */
    private const MIN_OVERALL_MATCH = 0.6;

    public function processOcr(VerificationDocument $doc): OcrResult
    {
        // BUG 1 FIXED: was 'ocr_status' (not in $fillable) → silently ignored.
        // BUG 2 FIXED: file_path is NOT NULL in migration; pass the doc's id_photo_path.
        $ocrResult = OcrResult::create([
            'verification_doc_id' => $doc->id,
            'user_id'             => $doc->user_id,
            'file_path'           => $doc->id_photo_path,  // ← added (required column)
            'status'              => 'processing',          // ← was 'ocr_status' => 'processing'
        ]);

        try {
            $idText = $this->extractTextFromImage($doc->id_photo_path);
            $parsed = $this->parseIdText($idText, $doc->id_type);

            $user         = $doc->user;
            $nameMatch    = $this->calculateNameMatch($user->first_name . ' ' . $user->last_name, $parsed['name'] ?? '');
            $dateMatch    = $this->calculateDateMatch($user->residentProfile?->birth_date, $parsed['birthdate'] ?? '');
            $overallMatch = ($nameMatch + $dateMatch) / 2;

            $ocrResult->update([
                'extracted_text'      => $idText,
                'extracted_name'      => $parsed['name'],
                'extracted_birthdate' => $parsed['birthdate'],
                'extracted_address'   => $parsed['address'],
                'extracted_id_number' => $parsed['id_number'],
                'raw_ocr_response'    => ['text' => $idText, 'parsed' => $parsed],
                'confidence_score'    => $parsed['confidence'] ?? 0.0,
                'name_match_score'    => $nameMatch,
                'date_match_score'    => $dateMatch,
                'overall_match'       => $overallMatch,
                // BUG 3 FIXED: was 'matched'/'mismatch' — not in enum.
                // Migration enum: pending | processing | approved | failed
                'status'              => $overallMatch >= self::MIN_OVERALL_MATCH ? 'approved' : 'failed',
                'processed_at'        => now(),
            ]);

            RegistrationApproval::where('user_id', $doc->user_id)
                ->update(['ocr_result_id' => $ocrResult->id]);

        } catch (\Throwable $e) {
            $ocrResult->update([
                'status'           => 'failed',
                'raw_ocr_response' => ['error' => $e->getMessage()],
                'processed_at'     => now(),
            ]);
        }

        return $ocrResult->fresh();
    }

    // =========================================================================
    // HELPERS — missing from the snippet this file was restored from
    // =========================================================================

    /**
     * OCR an uploaded ID via OCR.space — the same provider and endpoint
     * OcrController uses, so the system has exactly one OCR vendor.
     */
    private function extractTextFromImage(?string $storagePath): string
    {
        if (!$storagePath) {
            throw new \RuntimeException('No ID photo was attached to this verification document.');
        }

        $apiKey = (string) config('services.ocr_space.key');

        if ($apiKey === '') {
            throw new \RuntimeException('OCR_SPACE_API_KEY is not configured.');
        }

        $disk = Storage::disk('local');

        if (!$disk->exists($storagePath)) {
            throw new \RuntimeException('ID photo is missing from storage.');
        }

        $response = Http::timeout(90)
            ->attach('file', $disk->get($storagePath), basename($storagePath))
            ->post('https://api.ocr.space/parse/image', [
                'apikey'            => $apiKey,
                'language'          => 'eng',
                'isOverlayRequired' => 'false',
                'scale'             => 'true',
                'detectOrientation' => 'true',
                'OCREngine'         => '2',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('OCR provider returned HTTP ' . $response->status());
        }

        $payload = $response->json();

        if (!empty($payload['IsErroredOnProcessing'])) {
            $message = $payload['ErrorMessage'] ?? 'OCR provider could not read the image.';

            throw new \RuntimeException(is_array($message) ? implode('; ', $message) : (string) $message);
        }

        return trim((string) ($payload['ParsedResults'][0]['ParsedText'] ?? ''));
    }

    /**
     * Pull the fields we care about out of raw OCR text.
     *
     * Philippine IDs vary widely in layout, so this is label-driven and
     * forgiving rather than positional: it looks for the labels the cards
     * actually print, and reports how many it found as the confidence score.
     * A low confidence means "ask a human", never "reject the applicant".
     *
     * @return array{name: ?string, birthdate: ?string, address: ?string, id_number: ?string, id_type: ?string, confidence: float}
     */
    private function parseIdText(string $text, ?string $idType = null): array
    {
        $normalized = preg_replace('/\r\n?/', "\n", $text) ?? $text;

        $parsed = [
            'name'      => $this->matchLabelled($normalized, ['name', 'pangalan', 'full name']),
            'birthdate' => $this->normalizeDate(
                $this->matchLabelled($normalized, ['birth date', 'birthdate', 'date of birth', 'petsa ng kapanganakan', 'dob'])
            ),
            'address'   => $this->matchLabelled($normalized, ['address', 'tirahan']),
            'id_number' => $this->matchLabelled($normalized, ['id no', 'id number', 'pin', 'crn', 'card no']),
            'id_type'   => $idType,
        ];

        $found = count(array_filter([
            $parsed['name'],
            $parsed['birthdate'],
            $parsed['address'],
            $parsed['id_number'],
        ]));

        // 0.00 - 1.00, one step per field recovered.
        $parsed['confidence'] = round($found / 4, 2);

        return $parsed;
    }

    /**
     * Token-overlap score between the registered name and the scanned name.
     *
     * Deliberately order-insensitive: Philippine IDs print "SANTOS, MARIA
     * CLARA" while registration collects "Maria Clara Santos". Comparing the
     * strings directly would score a perfect match as a mismatch.
     */
    private function calculateNameMatch(?string $registeredName, ?string $extractedName): float
    {
        $left  = $this->nameTokens($registeredName);
        $right = $this->nameTokens($extractedName);

        if (empty($left) || empty($right)) {
            return 0.0;
        }

        $shared = array_intersect($left, $right);

        // Denominator is the SHORTER list: an ID that omits a middle name the
        // user registered should not be penalised for the omission.
        return round(count($shared) / min(count($left), count($right)), 2);
    }

    /**
     * Birthdates either agree or they do not. There is no partial credit for
     * being close — a near-miss on a date of birth is exactly the case a human
     * reviewer needs to see.
     */
    private function calculateDateMatch($registeredDate, ?string $extractedDate): float
    {
        $left = $this->normalizeDate(
            is_string($registeredDate)
                ? $registeredDate
                : ($registeredDate?->format('Y-m-d') ?? null)
        );

        $right = $this->normalizeDate($extractedDate);

        if (!$left || !$right) {
            return 0.0;
        }

        return $left === $right ? 1.0 : 0.0;
    }

    // =========================================================================

    /**
     * Find "Label: value" on its own line, case-insensitively.
     */
    private function matchLabelled(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            $pattern = '/^\s*' . preg_quote($label, '/') . '\s*[:.\-]\s*(.+)$/im';

            if (preg_match($pattern, $text, $matches)) {
                $value = trim($matches[1]);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string> lowercase name tokens, punctuation and initials removed
     */
    private function nameTokens(?string $name): array
    {
        $clean = strtolower(trim((string) $name));
        $clean = preg_replace('/[^a-z\s]/', ' ', $clean) ?? $clean;

        $tokens = array_filter(
            preg_split('/\s+/', $clean) ?: [],
            // Drop single letters: middle initials are noise, not signal.
            fn ($token) => strlen($token) > 1
        );

        return array_values(array_unique($tokens));
    }

    private function normalizeDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
