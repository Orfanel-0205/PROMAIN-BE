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
    /**
     * Heading words for each field, as written on paper forms.
     *
     * Includes the RHU's own Individual Treatment Record: "Chief Complaint"
     * over "S-", "O-", "A-", "P-" lines, then "Remarks & Diagnosis" and
     * "Prescribe Drug/s" for the doctor.
     */
    private const HEADINGS = [
        'subjective' => ['subjective', 'chief complaint', 'history of present illness', 'hpi', 'c/c', 'cc', 's'],
        'objective' => ['objective', 'physical examination', 'physical exam', 'p.e.', 'pe', 'o'],
        'assessment' => ['assessment', 'a'],
        'diagnosis' => ['remarks & diagnosis', 'remarks and diagnosis', 'remarks', 'diagnosis', 'impression', 'dx'],
        'plan' => ['planning', 'plan', 'p'],
        'treatment' => ['treatment', 'management', 'tx'],
        // The SOAP page's "Prescribed Drug/s" field.
        'prescribed_drugs' => ['prescribe drug/s', 'prescribed drug/s', 'prescribe drugs', 'prescribed drugs', 'prescribed drug', 'medications', 'meds', 'rx'],
        // Not a SOAP field: only searched for lab tests.
        'labs' => ['laboratory request', 'laboratory requests', 'lab request', 'lab requests', 'laboratory', 'labs', 'diagnostics', 'requests'],
    ];

    /**
     * The printed labels of the RHU's Individual Treatment Record.
     *
     * A photo of the folded form at an angle is read column by column, so the
     * right-hand column's empty labels ("Bdate:", "FP Method:", "Cp #:") and
     * checkbox rows ("Y N :Hypertension") turn up inside the S/O/A/P text --
     * measured on a real photo of the form. A line is dropped when nothing is
     * left of it but these labels, units and checkbox letters; anything with a
     * value ("Age: 34", "BP 110/70") or real words is kept.
     */
    private const FORM_LABELS = [
        'office of the municipal health officer', 'individual treatment record', 'municipality of malasiqui',
        'pediatric client aged 0-24 months', 'others, please specify', 'personal/social history',
        'past medical history', 'body circumference', 'head circumference', 'skinfold thickness',
        'for females only', 'consultation date', 'altered sensorium', "guardian's name", 'awake and alert',
        'period duration', 'menopausal age', 'general survey', 'visual acuity', 'civil status', 'client type',
        'no. of child', 'middle name', 'philhealth #', 'first name', 'blood type', 'full name', 'last name',
        'fp method', 'philhealth', 'education', 'dependent', 'religion', 'address', 'gender', 'member',
        'length', 'limbs', 'bdate', 'cycle', 'waist', 'cp #', 'muac', 'none', 'spo2', 'temp', 'age', 'bmi',
        'hip', 'lmp', 'v/s', 'bp', 'ht', 'wt', 'hr', 'pr', 'rr',
    ];

    /** The ITR's yes/no checklist; only dropped on a line with checkbox marks. */
    private const CHECKLIST_ITEMS = [
        'copd/emphysema/bronchitis', 'bronchial asthma', 'alcohol intake', 'heart disease', 'hypertension',
        'tuberculosis', 'emphysema', 'bronchitis', 'allergies', 'diabetes', 'smoking', 'cancer', 'stroke', 'copd',
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
            // Where requests are written: the plan, the doctor's remarks, the
            // drugs/treatment, and any "Labs" section.
            'lab_tests' => self::labTests(trim(implode("\n", [
                $sections['plan'] ?? '',
                $sections['diagnosis'] ?? '',
                $sections['treatment'] ?? '',
                $labs,
            ]))),
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

            if ($heading === null && self::isFormBoilerplate($line)) {
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

            // One letter needs a colon ("S:"), or a dash or full stop and a
            // space ("S- ", as on the RHU's own form, which OCR sometimes
            // reads as "p. ") -- never "A patient" or "A-fib". A word may
            // also stand alone on its line, or be followed by a dash.
            $pattern = strlen($word) === 1
                ? '/^\s*' . $quoted . '\s*(?::|[\-\x{2013}.](?=\s|$))\s*(.*)$/iu'
                : '/^\s*' . $quoted . '\s*(?:[:\-\x{2013}]\s*(.*)|$)/iu';

            if (preg_match($pattern, $line, $m) === 1) {
                return [$field, trim($m[1] ?? '')];
            }
        }

        return null;
    }

    /**
     * Whether a line is only the ITR's printing: empty labels, units, and
     * checkbox rows. See FORM_LABELS.
     */
    public static function isFormBoilerplate(string $line): bool
    {
        $text = mb_strtolower(trim($line));

        if ($text === '') {
            return false;
        }

        // Stray marks: "DY", "R", "cm".
        if (mb_strlen((string) preg_replace('/[^\p{L}\p{N}]/u', '', $text)) <= 2) {
            return true;
        }

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hasCheckboxMarks = str_contains($text, ':')
            && array_intersect($tokens, ['y', 'n', 'on', 'dy', 'ly', 'yn', 'd', 'o']) !== [];

        $matchedPrinting = false;

        if ($hasCheckboxMarks) {
            foreach (self::CHECKLIST_ITEMS as $item) {
                if (str_contains($text, $item)) {
                    $text = str_replace($item, ' ', $text);
                    $matchedPrinting = true;
                }
            }
        }

        foreach (self::FORM_LABELS as $label) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($label, '/') . '(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $text) === 1) {
                $text = (string) preg_replace($pattern, ' ', $text);
                $matchedPrinting = true;
            }
        }

        if (!$matchedPrinting) {
            return false;
        }

        $text = (string) preg_replace('/(?<![\p{L}\p{N}])(?:cm|°c|c|mm\/hg|kg)(?![\p{L}\p{N}])/u', ' ', $text);

        // Only checkbox letters left ("Y", "ON", "CM DD") -- no value, no words.
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/^\p{L}{1,2}$/u', $token) !== 1) {
                return false;
            }
        }

        return true;
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

        if (preg_match('/\bBMI\s*[:=]?\s*(\d{1,2}(?:[.,]\d{1,2})?)\b/iu', $text, $m) === 1) {
            $bmi = (float) str_replace(',', '.', $m[1]);

            if ($bmi >= 10 && $bmi <= 70) {
                $vitals['bmi'] = rtrim(rtrim(number_format($bmi, 1, '.', ''), '0'), '.');
            }
        }

        // "Visual Acuity: L 20/20  R 20/30", as on the RHU form.
        if (preg_match('/visual\s*acuity\s*[:=]?(.*)$/imu', $text, $line) === 1) {
            if (preg_match('/\bL\s*[:=\-]?\s*(\d{1,3}\s*\/\s*\d{1,3})/u', $line[1], $m) === 1) {
                $vitals['visual_acuity_left'] = preg_replace('/\s+/', '', $m[1]);
            }

            if (preg_match('/\bR\s*[:=\-]?\s*(\d{1,3}\s*\/\s*\d{1,3})/u', $line[1], $m) === 1) {
                $vitals['visual_acuity_right'] = preg_replace('/\s+/', '', $m[1]);
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
