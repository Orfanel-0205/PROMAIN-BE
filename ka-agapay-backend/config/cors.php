<?php
// config/cors.php
//
// Which other websites a browser may call this API from.
//
// This was '*': any page on the internet could call the API from a visitor's
// browser. With bearer tokens rather than session cookies that is not the
// disaster it would otherwise be — a random site still holds no token — but it
// gives away a free layer of protection for no benefit, and it is among the
// first things a buyer's IT reviewer looks for.
//
// Restricting it costs nothing here, because neither client that matters is
// subject to CORS in the first place:
//
//   The dashboard is served from the SAME origin as the API — nginx serves both
//   the site and /api on rhu-kaagapay... — so its requests are same-origin and
//   are never preflighted.
//
//   The mobile app is native. React Native sends no Origin header and no
//   browser is involved, so CORS does not apply to it either.
//
// What this list is actually for is development machines and Expo's web
// preview, and refusing everybody else.

$configured = trim((string) env('CORS_ALLOWED_ORIGINS', ''));

$origins = $configured !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $configured))))
    : array_values(array_filter([
        // Production. Same-origin in practice, listed so that moving the
        // dashboard onto its own hostname later does not fail mysteriously.
        rtrim((string) env('APP_URL', ''), '/') ?: null,
        rtrim((string) env('FRONTEND_URL', ''), '/') ?: null,

        // Local development (Vite).
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:5174',

        // Expo web preview.
        'http://localhost:8081',
        'http://localhost:19006',
    ]));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    /*
     * Add a deployment's own hostname through CORS_ALLOWED_ORIGINS (comma
     * separated) rather than by editing this file. Setting that to '*' is still
     * possible, and is exactly what this change exists to stop being the
     * default nobody revisits.
     */
    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 86400,

    // Bearer tokens, not cookies. Nothing here is sent with credentials, and
    // credentials alongside a wide origin list is the combination that does
    // real damage.
    'supports_credentials' => false,

];
