<?php

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        // ✅ THIS WAS MISSING — Sanctum uses this guard to validate Bearer tokens
        'api' => [
            'driver'   => 'sanctum',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => 'password_reset_tokens',
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

    /*
    |--------------------------------------------------------------------------
    | Sign-in codes after a wrong password
    |--------------------------------------------------------------------------
    |
    | When an account has had a wrong password since its last sign-in, the
    | right password alone is not enough: a six-digit code is texted to the
    | account holder's phone. See AppServicesAuthVerificationCodes.
    |
    | Either audience can be switched off from .env without a deploy. The
    | resident switch exists for the gap between this server change and a
    | new mobile build: older app builds show the "enter the code" message
    | but have no field to type it into.
    |
    */

    'login_codes' => [
        'admin'    => (bool) env('LOGIN_CODES_ADMIN', true),
        'resident' => (bool) env('LOGIN_CODES_RESIDENT', true),
    ],

];