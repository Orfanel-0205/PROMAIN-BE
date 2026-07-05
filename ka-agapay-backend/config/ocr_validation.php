<?php
// config/ocr_validation.php
//
// Tunable rules for validating that an uploaded ID image is a REAL government /
// employee ID — not a meme, selfie, landscape photo, or receipt. Everything the
// IdDocumentValidator checks is driven from here so thresholds can be adjusted
// during a demo without touching code. Override any value with an env var.

return [

    // ── File constraints (ID verification upload) ────────────────────────────
    'allowed_mime'   => ['image/jpeg', 'image/jpg', 'image/png'],
    'allowed_ext'    => ['jpg', 'jpeg', 'png'],
    'max_size_kb'    => (int) env('OCR_ID_MAX_SIZE_KB', 5120),   // 5 MB

    // ── Image geometry ───────────────────────────────────────────────────────
    // A real ID photo has a sensible resolution. Tiny thumbnails / icons fail.
    'min_width'      => (int) env('OCR_ID_MIN_WIDTH', 300),
    'min_height'     => (int) env('OCR_ID_MIN_HEIGHT', 300),
    // Guard against enormous panorama/screenshot uploads.
    'max_width'      => (int) env('OCR_ID_MAX_WIDTH', 8000),
    'max_height'     => (int) env('OCR_ID_MAX_HEIGHT', 8000),

    // Portrait check (width / height). The Malasiqui Employee ID is portrait, so
    // employee IDs must be roughly portrait or square. Resident IDs (PhilID,
    // driver's license, etc.) are often landscape, so orientation is NOT enforced
    // for them — only the keyword gate is.
    'employee_portrait' => [
        'enforce'      => (bool) env('OCR_ID_ENFORCE_PORTRAIT', true),
        'min_ratio'    => 0.45,  // width/height lower bound
        'max_ratio'    => 1.10,  // allow near-square; reject clearly landscape
    ],

    // ── Keyword gate ─────────────────────────────────────────────────────────
    // OCR text from a real ID contains recognisable markers. A meme/selfie/
    // landscape/receipt does not. Each matched keyword adds its weight; the
    // document passes when the total >= the required score for its category.
    // Matching is case-insensitive and fuzzy (ignores spaces/punctuation) so OCR
    // noise like "REPUBLIKA NG PILIPINAS" partial reads still count.
    'keywords' => [

        // Strong markers — specific to a Malasiqui LGU / PH government ID.
        'strong' => [
            'republic of the philippines' => 3,
            'republika ng pilipinas'      => 3,
            'province of pangasinan'       => 3,
            'pangasinan'                   => 2,
            'malasiqui'                    => 3,
            'local government unit'        => 2,
            'municipal'                    => 1,
            'municipality'                 => 1,
        ],

        // Employee-ID specific markers.
        'employee' => [
            'employee identification'      => 3,
            'employee id'                  => 3,
            'identification card'          => 2,
            'office'                       => 1,
            'position'                     => 1,
            'designation'                  => 1,
            'municipal mayor'              => 2,
            'rural health unit'            => 2,
            'rhu'                          => 1,
        ],

        // Generic ID field markers — present on most national IDs (used to keep
        // legitimate resident IDs from being rejected).
        'id_fields' => [
            'date of birth'                => 2,
            'birth'                        => 1,
            'address'                      => 1,
            'signature'                    => 1,
            'sex'                          => 1,
            'nationality'                  => 1,
            'valid until'                  => 1,
            'id no'                        => 1,
            'license'                      => 1,
            'identification'               => 2,
            'philsys'                      => 3,
            'philhealth'                   => 3,
            'passport'                     => 3,
            'driver'                       => 2,
        ],
    ],

    // Minimum total keyword score required to accept the document.
    'min_keyword_score' => [
        'employee_id' => (int) env('OCR_ID_MIN_SCORE_EMPLOYEE', 5),
        'resident_id' => (int) env('OCR_ID_MIN_SCORE_RESIDENT', 3),
    ],

    // Minimum characters of readable text — a photo that OCRs to almost nothing
    // (blank wall, dark selfie) is rejected before keyword scoring.
    'min_text_length' => (int) env('OCR_ID_MIN_TEXT_LENGTH', 15),

    // ── Duplicate detection ──────────────────────────────────────────────────
    // Rejects the SAME image being reused across accounts. Requires an
    // `image_hash` column on `ocr_results` (see the optional migration). When the
    // column is absent the check is silently skipped — nothing breaks.
    'duplicate' => [
        'enforce' => (bool) env('OCR_ID_ENFORCE_DUPLICATE', true),
    ],
];
