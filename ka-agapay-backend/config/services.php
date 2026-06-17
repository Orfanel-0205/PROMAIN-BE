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

    'google' => [
        'gemini_api_key' => env('GEMINI_API_KEY'),
        'vision_api_key' => env('GOOGLE_VISION_API_KEY'),
    ],

    'ocr_space' => [
        'key' => env('OCR_SPACE_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS Provider
    |--------------------------------------------------------------------------
    */

    'sms_provider' => env('SMS_PROVIDER', 'semaphore'),

'semaphore' => [
    'api_key' => env('SEMAPHORE_API_KEY'),
    'sendername' => env('SEMAPHORE_SENDERNAME', 'KAAGAPAY'),
    'base_url' => env('SEMAPHORE_BASE_URL', 'https://api.semaphore.co/api/v4'),
],

];