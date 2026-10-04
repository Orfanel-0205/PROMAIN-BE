<?php
// app/Services/Consultation/SoapScanParser.php

namespace App\Services\Consultation;

use App\Support\LabTestCatalogue;

/**
 * Turns the text read from a photographed paper SOAP form into suggestions
 * for the SOAP page: the SOAP sections, the vital signs, and any lab tests
 * requested.
 *
 * Suggestions only. The page puts them into EMPTY fields and never over what
 * staff typed, and nothing is saved until staff save the SOAP. OCR misreads,
 * handwriting worst of all, so a person checks every field.
 *
 * SECTIONS
 * --------
 * Found by the headings a paper form uses, at the start of a line: "S:",
 * "Subjective", "CC", "O:", "PE", "A:", "Assessment", "Dx", "Impression",
 * "P:", "Plan", "Rx", "Treatment". Single letters count only with a colon,
 * so the word "A" in a sentence is never a heading. Text above the first
 * heading -- the patient's name and the date on the form -- is ignored.
 *
 * LAB TESTS
 * ---------
 * Matched against the RHU's own catalogue (App\Support\LabTestCatalogue), by
 * stored value, full name, the abbreviation in the name ("RBS", "SGPT") and
 * the shorthand clinicians write ("U/A", "CXR", "EKG"). Only in the Plan and
 * in a "Labs"/"Request" section: the exam notes say "upper abdomen" and
 * "prostate" far more often than they request an ultrasound of them.
 */
final class SoapScanParser
{
    /** Heading words for each field, as written on paper forms. */
    private const HEADINGS = [
        'subjective' => ['subjective', 'chief complaint', 'history of present illness', 'hpi', 'c/c', 'cc', 's'],
        'objective' => ['objective', 'physical examination', 'physical exam', 'p.e.', 'pe', 'o'],
        'assessment' => ['assessment', 'a'],
        'diagnosis' => ['diagnosis', 'impression', 'dx'],
        'plan' => ['plan', 'p'],
        'treatment' => ['treatment', 'management', 'medications', 'meds', 'tx', 'rx'],
        // Not a SOAP field: only searched for lab tests.
        'labs' => ['laboratory request', 'laboratory requests', 'lab request', 'lab requests', 'laboratory', 'labs', 'diagnostics', 'requests'],
    ];

    /** Shorthand clinicians write, mapped to the catalogue's stored values. */
    private const ALIASES = [
        'laboratory' => [
            'U/A' => 'Urinalysis',
            'UA' => 'Urinalysis',
            'EKG' => 'Electrocardiogram (ECG)',
            'PAP' => 'Pap Smear',
            'LIPID PROFILE' => 'Total Lipid Profile',
            'FECALYSIS' => 'Fecalysis',
            'GENEXPERT' => 'MTB/RIF Exam',
        ],
        'xray' => [
            'CXR' => 'CXR - PA View',
            'CHEST X-RAY' => 'CXR - PA View',
            'CHEST XRAY' => 'CXR - PA View',
            'CXR APL' => 'CXR - Apicolordotic View',
        ],
        'ultrasound' => [],
    ];

    /**
     * @return array{
     *   fields: array<string, string>,
     *   vitals: array<string, string>,
     *   lab_tests: array{laboratory: array<int, string>, xray: array<int, string>, ultrasound: array<int, string>}
     * }
     */
    public static function parse(string $text): array
    {
        $sections = self::sections($text);

        $labs = $sections['labs'] ?? '';
        unset($sections['labs']);

        return [
            'fields' => $sections,
            'vitals' => self::vitals($text),
            'lab_tests' => self::labTests(trim(($sections['plan'] ?? '') . "\n" . ($sections['treatment'] ?? '') . "\n" . $labs)),
        ];
    }

    /** @return array<string, string> field => text, non-empty only */
    private static function sections(string $text): array
    {
        $alternatives = [];

        foreach (self::HEADINGS as $field => $words) {
            foreach ($words as $word) {
                $alternatives[$word] = $field;
            }
        }

        // Longest first, so "plan" is not read as "p" + "lan".
        uksort($alternatives, fn ($a, $b) => strlen($b) <=> strlen($a));

        $collected = [];
        $current = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $heading = self::heading($line, $alternatives);

            if ($heading !== null) {
                [$current, $rest] = $heading;
                $line = $rest;
            }

            if ($current === null) {
                continue;
            }

            $collected[$current][] = trim($line);
        }

        $out = [];

        foreach ($collected as $field => $lines) {
            $body = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');

            if ($body !== '') {
                $out[$field] = isset($out[$field]) ? $out[$field] . "\n" . $body : $body;
            }
        }

        return $out;
    }

    /**
     * [field, rest of line] when the line starts with a heading.
     *
     * @param array<string, string> $alternatives
     * @return array{0: string, 1: string}|null
     */
    private static function heading(string $line, array $alternatives): ?array
    {
        foreach ($alternatives as $word => $field) {
            $quoted = preg_quote($word, '/');

            // One letter needs a colon ("S:"); a word may also stand alone on
            // its line, or be followed by a dash.
            $pattern = strlen($word) === 1
                ? '/^\s*' . $quoted . '\s*:\s*(.*)$/iu'
                : '/^\s*' . $quoted . '\s*(?:[:\-\x{2013}]\s*(.*)|$)/iu';

            if (preg_match($pattern, $line, $m) === 1) {
                return [$field, trim($m[1] ?? '')];
            }
        }

        return null;
    }

    /** @return array<string, string> the SOAP page's vital-sign fields */
    private static function vitals(string $text): array
    {
        $vitals = [];

        if (preg_match('/\b(?:BP|B\/P|blood\s*pressure)\s*[:=]?\s*(\d{2,3})\s*\/\s*(\d{2,3})/iu', $text, $m) === 1) {
            $vitals['blood_pressure'] = "{$m[1]}/{$m[2]}";
        }

        if (preg_match('/\b(?:temp(?:erature)?|T)\s*[:=]?\s*(\d{2}(?:[.,]\d{1,2})?)\s*(?:°|deg)?\s*C?\b/iu', $text, $m) === 1) {
            $celsius = (float) str_replace(',', '.', $m[1]);

            if ($celsius >= 30 && $celsius <= 45) {
                $vitals['temperature_celsius'] = rtrim(rtrim(number_format($celsius, 1, '.', ''), '0'), '.');
            }
        }

        if (preg_match('/\b(?:HR|PR|CR|pulse(?:\s*rate)?|heart\s*rate)\s*[:=]?\s*(\d{2,3})\b/iu', $text, $m) === 1
            && (int) $m[1] >= 20 && (int) $m[1] <= 250) {
            $vitals['heart_rate'] = $m[1];
        }

        if (preg_match('/\b(?:SpO2|SaO2|O2\s*sat(?:uration)?)\s*[:=]?\s*(\d{2,3})\s*%?/iu', $text, $m) === 1
            && (int) $m[1] >= 50 && (int) $m[1] <= 100) {
            $vitals['spo2'] = $m[1];
        }

        if (preg_match('/\b(?:WT|weight)\s*[:=]?\s*(\d{1,3}(?:[.,]\d+)?)\s*(kgs?|lbs?)?/iu', $text, $m) === 1) {
            $weight = (float) str_replace(',', '.', $m[1]);

            // The SOAP page keeps kilograms.
            if (isset($m[2]) && stripos($m[2], 'lb') === 0) {
                $weight = round($weight * 0.453592, 1);
            }

            if ($weight > 0 && $weight < 400) {
                $vitals['weight'] = rtrim(rtrim(number_format($weight, 1, '.', ''), '0'), '.');
            }
        }

        // Respiratory rate has no field of its own; it goes with the vital signs.
        if (preg_match('/\b(?:RR|resp(?:iratory)?\s*rate)\s*[:=]?\s*(\d{1,2})\b/iu', $text, $m) === 1
            && (int) $m[1] >= 5 && (int) $m[1] <= 80) {
            $vitals['vital_signs'] = "RR {$m[1]}";
        }

        return $vitals;
    }

    /** @return array{laboratory: array<int, string>, xray: array<int, string>, ultrasound: array<int, string>} */
    private static function labTests(string $text): array
    {
        $found = ['laboratory' => [], 'xray' => [], 'ultrasound' => []];

        if (trim($text) === '') {
            return $found;
        }

        foreach (array_keys($found) as $section) {
            $terms = [];

            foreach (LabTestCatalogue::flat($section) as $test) {
                $terms[$test['value']] = $test['value'];
                $terms[$test['label']] = $test['value'];

                // "Fasting Blood Sugar (FBS)" -> "Fasting Blood Sugar" and "FBS".
                if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/u', $test['label'], $m) === 1) {
                    $terms[trim($m[1])] = $test['value'];
                    $terms[trim($m[2])] = $test['value'];
                }
            }

            foreach (self::ALIASES[$section] as $alias => $value) {
                $terms[$alias] = $value;
            }

            // Longest first: "CXR APL" before "CXR".
            uksort($terms, fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));

            // Ultrasound regions are body parts ("upper abdomen", "prostate")
            // that notes mention all the time; they count only on a line that
            // asks for an ultrasound.
            $remaining = $section === 'ultrasound'
                ? implode("\n", array_filter(
                    preg_split('/\R/u', $text) ?: [],
                    fn ($line) => preg_match('/\b(?:ultrasound|ultrasonography|sonograph\w*|utz|ultz)\b/iu', $line) === 1
                ))
                : $text;

            foreach ($terms as $term => $value) {
                $pattern = '/(?<![\p{L}\p{N}])' . preg_quote((string) $term, '/') . '(?![\p{L}\p{N}])/iu';

                if (preg_match($pattern, $remaining) === 1) {
                    if (!in_array($value, $found[$section], true)) {
                        $found[$section][] = $value;
                    }

                    // So "CXR" inside "CXR APL" is not counted again.
                    $remaining = (string) preg_replace($pattern, ' ', $remaining);
                }
            }
        }

        return $found;
    }
}
