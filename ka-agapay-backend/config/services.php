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

    /*
    |--------------------------------------------------------------------------
    | Telemedicine Video Provider (Jitsi / JaaS / self-hosted)
    |--------------------------------------------------------------------------
    |
    | provider:
    |   - jaas            : 8x8 Jitsi-as-a-Service (production, JWT required)
    |   - self_hosted     : your own Jitsi server (production)
    |   - meet_public_demo: public meet.jit.si (DEMO ONLY — disconnects after 5 min)
    |
    | The default is intentionally NOT meet.jit.si so production never silently
    | falls back to the 5-minute demo embed.
    */

    'jitsi' => [
        'provider'     => env('JITSI_PROVIDER', 'self_hosted'),
        'domain'       => env('JITSI_DOMAIN', 'meet.kaagapay.local'),
        'app_id'       => env('JITSI_APP_ID'),
        'app_secret'   => env('JITSI_APP_SECRET'),
        'api_key'      => env('JITSI_API_KEY'),       // JaaS kid (key id)
        'private_key'  => env('JITSI_PRIVATE_KEY'),   // JaaS RS256 PEM (optional)
        'jwt_enabled'  => env('JITSI_JWT_ENABLED', false),
        'room_prefix'  => env('JITSI_ROOM_PREFIX', 'kaagapay-rhu1'),
    ],

    'sms_provider' => env('SMS_PROVIDER', 'semaphore'),

'semaphore' => [
    'api_key' => env('SEMAPHORE_API_KEY'),
    'sendername' => env('SEMAPHORE_SENDERNAME', 'KAAGAPAY'),
    'base_url' => env('SEMAPHORE_BASE_URL', 'https://api.semaphore.co/api/v4'),
],

];