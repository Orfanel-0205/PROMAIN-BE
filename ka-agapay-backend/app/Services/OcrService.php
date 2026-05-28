<?php
// app/Services/OcrService.php
// OCR pipeline for Ka-agapay:
//   1. Image preprocessing (via Intervention Image + shell convert)
//   2. Tesseract OCR extraction
//   3. Structured prescription field parsing
//   4. Confidence scoring
//
// Requirements:
//   • tesseract-ocr installed on the server  (sudo apt install tesseract-ocr)
//   • ImageMagick installed                   (sudo apt install imagemagick)
//   • intervention/image composer package     (composer require intervention/image)

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class OcrService
{
    private string $tesseractBin;
    private string $convertBin;

    public function __construct()
    {
        $this->tesseractBin = config('ocr.tesseract_bin', '/usr/bin/tesseract');
        $this->convertBin   = config('ocr.convert_bin',   '/usr/bin/convert');
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Full pipeline: preprocess → OCR → parse → return structured result.
     *
     * @param  string  $storagePath  Path under Storage::disk('local')
     * @return array{
     *   raw_text: string,
     *   confidence: int,
     *   fields: array,
     *   id_verified: bool,
     *   extracted_text: string
     * }
     */
    public function process(string $storagePath, string $idType = 'prescription'): array
    {
        $absolutePath  = Storage::disk('local')->path($storagePath);
        $preprocessed  = $this->preprocess($absolutePath);

        ['text' => $rawText, 'confidence' => $confidence] = $this->runTesseract($preprocessed);

        $fields = match(true) {
            str_contains(strtolower($idType), 'prescription') => $this->parsePrescription($rawText),
            default                                            => $this->parseGenericId($rawText),
        };

        // Cleanup temp file
        @unlink($preprocessed);

        return [
            'raw_text'       => $rawText,
            'extracted_text' => $rawText,
            'confidence'     => $confidence,
            'fields'         => $fields,
            'id_verified'    => $confidence >= 60 && !empty(trim($rawText)),
        ];
    }

    // =========================================================================
    // STEP 1 — Image Preprocessing
    // =========================================================================

    /**
     * Uses ImageMagick to:
     *   • Convert to greyscale
     *   • Increase contrast
     *   • Apply threshold (binarise)
     *   • Remove noise (median filter)
     *   • Deskew
     *   • Output as high-res PNG for Tesseract
     *
     * Returns path to the preprocessed temp file.
     */
    private function preprocess(string $inputPath): string
    {
        $outputPath = sys_get_temp_dir() . '/ka_agapay_ocr_' . uniqid() . '.png';

        $cmd = implode(' ', [
            escapeshellarg($this->convertBin),
            escapeshellarg($inputPath),
            '-colorspace Gray',          // greyscale
            '-contrast-stretch 2%x2%',   // auto-level contrast
            '-threshold 50%',            // binarise
            '-median 1',                 // noise reduction
            '-deskew 80%',               // straighten tilted text
            '-density 300',              // 300 DPI for Tesseract
            '-units PixelsPerInch',
            '-type Grayscale',
            escapeshellarg($outputPath),
        ]);

        exec($cmd . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($outputPath)) {
            Log::warning('[OCR] Preprocessing failed, falling back to original', [
                'output' => implode("\n", $output),
            ]);
            // Fallback: copy the original so the rest of the pipeline still works
            copy($inputPath, $outputPath);
        }

        return $outputPath;
    }

    // =========================================================================
    // STEP 2 — Tesseract OCR
    // =========================================================================

    /**
     * @return array{text: string, confidence: int}
     */
    private function runTesseract(string $imagePath): array
    {
        $outBase = sys_get_temp_dir() . '/ka_agapay_tess_' . uniqid();

        $process = new Process([
            $this->tesseractBin,
            $imagePath,
            $outBase,
            '--oem', '3',            // LSTM neural-net mode
            '--psm', '6',            // Assume uniform block of text
            '-l', 'eng',
            'tsv',                   // TSV output includes confidence per word
        ]);

        $process->setTimeout(30);
        $process->run();

        $text       = '';
        $confidence = 0;

        $tsvPath = $outBase . '.tsv';

        if (file_exists($tsvPath)) {
            [$text, $confidence] = $this->parseTsv($tsvPath);
            @unlink($tsvPath);
        } elseif ($process->isSuccessful()) {
            $txtPath = $outBase . '.txt';
            if (file_exists($txtPath)) {
                $text = trim(file_get_contents($txtPath));
                @unlink($txtPath);
            }
        } else {
            Log::error('[OCR] Tesseract failed: ' . $process->getErrorOutput());
        }

        return ['text' => $text, 'confidence' => $confidence];
    }

    /**
     * Parses Tesseract TSV output to get the average word confidence.
     *
     * @return array{0: string, 1: int}
     */
    private function parseTsv(string $tsvPath): array
    {
        $lines      = file($tsvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $words      = [];
        $confidences = [];

        // TSV columns: level page_num block_num par_num line_num word_num left top width height conf text
        foreach (array_slice($lines, 1) as $line) {
            $cols = explode("\t", $line);
            if (count($cols) >= 12) {
                $conf = (int) $cols[10];
                $word = $cols[11] ?? '';

                if ($conf >= 0 && trim($word) !== '') {
                    $words[]       = $word;
                    $confidences[] = $conf;
                }
            }
        }

        $avgConfidence = empty($confidences) ? 0 : (int) (array_sum($confidences) / count($confidences));
        $text          = implode(' ', $words);

        return [$text, $avgConfidence];
    }

    // =========================================================================
    // STEP 3 — Structured Field Parsing
    // =========================================================================

    /**
     * Parses a prescription image's OCR text into structured fields.
     * The regexes cover common PH prescription formats.
     *
     * @return array{
     *   medicines: array,
     *   doctor_name: string|null,
     *   date: string|null,
     *   notes: string|null
     * }
     */
    private function parsePrescription(string $text): array
    {
        $medicines = [];

        // Medicine pattern: "1. Metformin 500mg Tab — 1 tab BID x 30 days"
        preg_match_all(
            '/(?:\d+\.\s*)?([A-Z][a-zA-Z]+(?:\s+[A-Z]?[a-zA-Z]+)*)\s+'
            . '(\d+\s?(?:mg|mcg|g|ml|IU|units?))\s*'
            . '(?:(Tab|Cap|Syrup|Susp|Inj|Cream|Oint|Drops?|Patch)\s+)?'
            . '(?:([\d.]+\s*(?:tab|cap|ml|drop|puff|unit)s?(?:\s*\/\s*dose)?)\s+)?'
            . '((?:OD|BID|TID|QID|PRN|q\d+h|once|twice|thrice|every\s+\d+\s+hours?)[^,\n]*)?'
            . '(?:x\s*([\d]+\s+(?:days?|weeks?|months?)))?/i',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $medicines[] = [
                'name'      => trim($m[1]),
                'dosage'    => trim($m[2]),
                'form'      => trim($m[3] ?? ''),
                'per_dose'  => trim($m[4] ?? ''),
                'frequency' => trim($m[5] ?? ''),
                'duration'  => trim($m[6] ?? ''),
            ];
        }

        // Doctor name — "Dr. Juan dela Cruz" or "Physician: Dr. ..."
        preg_match('/(?:Dr\.?\s+|Physician:\s*Dr\.?\s*)([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/i', $text, $docMatch);

        // Date — "May 28, 2026" or "05/28/2026"
        preg_match('/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}|\w+ \d{1,2},? \d{4})/i', $text, $dateMatch);

        // Everything else (doctor notes / instructions)
        $notes = preg_replace(
            '/(\d+\.\s*)?[A-Z][a-z]+ \d+\s?(mg|mcg|g|ml|IU)[^\n]*/i',
            '',
            $text
        );
        $notes = trim(preg_replace('/\s{2,}/', ' ', $notes));

        return [
            'medicines'   => $medicines,
            'doctor_name' => $docMatch[1] ?? null,
            'date'        => $dateMatch[1] ?? null,
            'notes'       => $notes ?: null,
        ];
    }

    /**
     * Generic ID parsing — extracts name, ID number, expiry date.
     */
    private function parseGenericId(string $text): array
    {
        preg_match('/(?:NO\.?|Number:?|ID:?)\s*([A-Z0-9\-]+)/i', $text, $idNum);
        preg_match('/(?:VALID UNTIL|EXPIRY|EXPIRES?)[:\s]+(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/i', $text, $expiry);
        preg_match('/(?:NAME|PANGALAN)[:\s]+([A-Z ,]+)/i', $text, $name);

        return [
            'id_number'  => $idNum[1]  ?? null,
            'name'       => $name[1]   ?? null,
            'expiry'     => $expiry[1] ?? null,
        ];
    }
}