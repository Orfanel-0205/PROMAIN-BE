<?php
// app/Support/LabTestCatalogue.php

namespace App\Support;

/**
 * The laboratory tests RHU Malasiqui actually offers.
 *
 * WHERE THIS LIST CAME FROM
 * -------------------------
 * The RHU's own IT staff supplied the catalogue from the system the unit runs
 * today, and asked for a replacement form because theirs is hard to use: about
 * thirty unordered checkboxes in two columns inside a scrolling modal, with no
 * grouping and no search. The tests are therefore theirs; only the arrangement
 * is ours.
 *
 * WHY value AND label
 * -------------------
 * `value` is what gets stored in prescriptions.lab_tests and what the printed
 * request matches on. It must never change once a test has been requested even
 * once, or every prescription already saved would quietly stop ticking that
 * box. `label` is what a human reads and can be reworded freely.
 *
 * That split is why the eleven original tests keep terse values — 'CBC',
 * 'FBS', 'HBA1C', 'B.U.A' — while reading as the full names the RHU uses. The
 * alternative, renaming the stored values, would have silently blanked those
 * tests on every prescription printed from an existing record.
 *
 * ADDING A TEST
 * -------------
 * Add it here and it appears in the admin form, in the printed request and in
 * reporting at once. The admin's own copy is src/constants/labTests.ts and the
 * two are checked against each other by a test.
 */
final class LabTestCatalogue
{
    /**
     * Grouped so a clinician scans headings rather than thirty checkboxes.
     * Groups are display only; nothing downstream depends on them.
     *
     * @var array<int, array{group:string, tests:array<int, array{value:string,label:string}>}>
     */
    public const LABORATORY = [
        [
            'group' => 'Hematology',
            'tests' => [
                ['value' => 'CBC', 'label' => 'Complete Blood Count (CBC)'],
                ['value' => 'Hematology', 'label' => 'Hematology'],
                ['value' => 'Blood Chemistry', 'label' => 'Blood Chemistry'],
            ],
        ],
        [
            'group' => 'Blood sugar',
            'tests' => [
                ['value' => 'FBS', 'label' => 'Fasting Blood Sugar (FBS)'],
                ['value' => 'Random Blood Sugar', 'label' => 'Random Blood Sugar (RBS)'],
                ['value' => 'Oral Glucose Tolerance Test', 'label' => 'Oral Glucose Tolerance Test (OGTT)'],
                ['value' => 'HBA1C', 'label' => 'HbA1c'],
            ],
        ],
        [
            'group' => 'Lipids and organ function',
            'tests' => [
                ['value' => 'Total Lipid Profile', 'label' => 'Lipid Profile'],
                ['value' => 'Creatinine', 'label' => 'Creatinine'],
                ['value' => 'B.U.N', 'label' => 'Blood Urea Nitrogen (BUN)'],
                ['value' => 'B.U.A', 'label' => 'Blood Uric Acid (BUA)'],
                ['value' => 'ALT', 'label' => 'ALT (SGPT)'],
                ['value' => 'AST', 'label' => 'AST (SGOT)'],
            ],
        ],
        [
            'group' => 'Urine and stool',
            'tests' => [
                ['value' => 'Urinalysis', 'label' => 'Urinalysis'],
                ['value' => 'Fecalysis', 'label' => 'Fecalysis'],
                ['value' => 'Fecal Occult Blood Test', 'label' => 'Fecal Occult Blood Test'],
            ],
        ],
        [
            // The National TB Programme tests. Kept together because they are
            // ordered together and reported together.
            'group' => 'Tuberculosis',
            'tests' => [
                ['value' => 'Direct Sputum Smear Microscopy', 'label' => 'Direct Sputum Smear Microscopy (DSSM)'],
                ['value' => 'MTB/RIF Exam', 'label' => 'MTB/RIF Exam (GeneXpert)'],
                ['value' => 'PPD Test (Tuberculosis)', 'label' => 'PPD Test (Tuberculin)'],
            ],
        ],
        [
            'group' => 'Infectious disease',
            'tests' => [
                ['value' => 'Dengue RDT', 'label' => 'Dengue RDT'],
                ['value' => 'Malaria RDT', 'label' => 'Malaria RDT'],
                ['value' => 'Syphilis Test', 'label' => 'Syphilis Test'],
                ['value' => 'Serology', 'label' => 'Serology'],
            ],
        ],
        [
            'group' => 'Microscopy and smears',
            'tests' => [
                ['value' => 'Microscopy', 'label' => 'Microscopy'],
                ['value' => 'Gram Stain', 'label' => 'Gram Stain'],
                ['value' => 'Wet Smear', 'label' => 'Wet Smear'],
                ['value' => '10% Potassium Hydroxide (KOH)', 'label' => '10% Potassium Hydroxide (KOH)'],
                ['value' => 'Skin Slit Smear', 'label' => 'Skin Slit Smear'],
            ],
        ],
        [
            'group' => 'Women\'s health',
            'tests' => [
                ['value' => 'Pap Smear', 'label' => 'Pap Smear'],
                ['value' => 'Cervical Cancer Screening', 'label' => 'Cervical Cancer Screening'],
            ],
        ],
        [
            'group' => 'Procedures',
            'tests' => [
                ['value' => 'Electrocardiogram (ECG)', 'label' => 'Electrocardiogram (ECG)'],
                ['value' => 'Biopsy', 'label' => 'Biopsy'],
            ],
        ],
    ];

    /**
     * The RHU list carries a bare "Chest X-ray" and a bare "X-ray". The two
     * view-specific entries here predate it and are kept, because a request
     * that does not say which view leaves the radiographer guessing. A bare
     * "Chest X-ray" is therefore not offered a second time; "X-ray (other
     * region)" covers everything else, with the region named in Others.
     */
    public const XRAY = [
        [
            'group' => 'Chest',
            'tests' => [
                ['value' => 'CXR - PA View', 'label' => 'Chest X-ray — PA View'],
                ['value' => 'CXR - Apicolordotic View', 'label' => 'Chest X-ray — Apicolordotic View'],
            ],
        ],
        [
            'group' => 'Other',
            'tests' => [
                ['value' => 'X-ray', 'label' => 'X-ray — other region (name it in Others)'],
            ],
        ],
    ];

    /**
     * The RHU list has a single bare "Ultrasound". These six sites predate it
     * and are more useful to a sonographer, so no bare entry is added — an
     * unlisted area goes in Others.
     */
    public const ULTRASOUND = [
        [
            'group' => 'Abdomen',
            'tests' => [
                ['value' => 'Whole Abdomen', 'label' => 'Whole Abdomen'],
                ['value' => 'Upper Abdomen', 'label' => 'Upper Abdomen'],
                ['value' => 'Lower Abdomen', 'label' => 'Lower Abdomen'],
            ],
        ],
        [
            'group' => 'Other areas',
            'tests' => [
                ['value' => 'HBT', 'label' => 'Hepatobiliary Tree (HBT)'],
                ['value' => 'KUB', 'label' => 'Kidneys, Ureters, Bladder (KUB)'],
                ['value' => 'Prostate', 'label' => 'Prostate'],
            ],
        ],
    ];

    /**
     * Flat {value, label} pairs for one section, in display order.
     *
     * @return array<int, array{value:string,label:string}>
     */
    public static function flat(string $section): array
    {
        $groups = match ($section) {
            'laboratory' => self::LABORATORY,
            'xray' => self::XRAY,
            'ultrasound' => self::ULTRASOUND,
            default => [],
        };

        $flat = [];

        foreach ($groups as $group) {
            foreach ($group['tests'] as $test) {
                $flat[] = $test;
            }
        }

        return $flat;
    }

    /** Every stored value for one section, for validation and cross-checks. */
    public static function values(string $section): array
    {
        return array_column(self::flat($section), 'value');
    }

    /**
     * The label to print for a stored value.
     *
     * Falls back to the value itself so a test that was removed from the
     * catalogue after it was requested still prints — as the words that were
     * stored, rather than vanishing from a request a patient is carrying.
     */
    public static function label(string $section, string $value): string
    {
        foreach (self::flat($section) as $test) {
            if ($test['value'] === $value) {
                return $test['label'];
            }
        }

        return $value;
    }

    /**
     * Selected values that are not in the catalogue.
     *
     * The printed form ticks boxes by matching the catalogue, so anything
     * selected but unlisted would not appear at all. The template prints these
     * underneath instead. That happens when a test is retired, or when a record
     * predates a rename.
     *
     * @param  array<int, string>  $selected
     * @return array<int, string>
     */
    public static function unlisted(string $section, array $selected): array
    {
        $known = self::values($section);

        return array_values(array_filter(
            array_map('strval', $selected),
            fn (string $value) => $value !== '' && !in_array($value, $known, true)
        ));
    }
}
