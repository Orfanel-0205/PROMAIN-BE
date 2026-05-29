<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'mailgun' => [
        'domain'   => env('MAILGUN_DOMAIN'),
        'secret'   => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme'   => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // ── Google / Gemini ───────────────────────────────────────────────────────
    'google' => [
        'gemini_api_key'     => env('GEMINI_API_KEY'),
        'vision_api_key'     => env('GOOGLE_VISION_API_KEY'),
    ],
    // OCR 
    'ocr_space' => [
    'key' => env('OCR_SPACE_API_KEY'),
],

];