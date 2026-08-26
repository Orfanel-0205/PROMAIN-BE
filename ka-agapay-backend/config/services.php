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

    /*
     * Jitsi / JaaS (8x8) video provider.
     *
     * Canonical variable names are the first env() in each chain below. The
     * extra names are accepted aliases: production was provisioned with
     * JITSI_API_KEY_ID for the JaaS key id and kept the PEM path in
     * JITSI_APP_SECRET, which silently resolved api_key/private_key to NULL and
     * left JaaS unauthenticated. Reading both spellings makes the deployed box
     * work as-is while the canonical names are adopted.
     *
     * 'private_key' may hold EITHER an inline PEM or a filesystem path to one;
     * JitsiTokenService resolves and validates it (and never logs its contents).
     */
    'jitsi' => [
        'provider'     => env('JITSI_PROVIDER', 'self_hosted'),
        'domain'       => env('JITSI_DOMAIN', 'meet.kaagapay.local'),

        // JaaS tenant / AppID (e.g. vpaas-magic-cookie-xxxxxxxx).
        'app_id'       => env('JITSI_APP_ID'),

        // self_hosted HS256 shared secret. NOTE: on the current production box
        // this also carries the JaaS PEM path, so it is used as a last-resort
        // private-key fallback when provider=jaas (see JitsiTokenService).
        'app_secret'   => env('JITSI_APP_SECRET'),

        // JaaS API key id -> becomes the JWT "kid" header.
        'api_key'      => env('JITSI_API_KEY') ?: env('JITSI_API_KEY_ID'),

        // JaaS RS256 private key: inline PEM or a path to the .pem file.
        'private_key'  => env('JITSI_PRIVATE_KEY') ?: env('JITSI_PRIVATE_KEY_PATH'),

        'jwt_enabled'  => env('JITSI_JWT_ENABLED', false),
        'room_prefix'  => env('JITSI_ROOM_PREFIX', 'kaagapay-rhu1'),

        // Minutes a minted room token stays valid.
        'jwt_ttl_minutes' => (int) env('JITSI_JWT_TTL_MINUTES', 120),
    ],

    'sms_provider' => env('SMS_PROVIDER', 'semaphore'),

'semaphore' => [
    'api_key' => env('SEMAPHORE_API_KEY'),
    'sendername' => env('SEMAPHORE_SENDERNAME', 'KAAGAPAY'),
    'base_url' => env('SEMAPHORE_BASE_URL', 'https://api.semaphore.co/api/v4'),
],

];