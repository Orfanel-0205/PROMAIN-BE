<?php
// app/Http/Middleware/SecurityHeaders.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        return $response
            ->header('X-Content-Type-Options',    'nosniff')
            ->header('X-Frame-Options',            'DENY')
            ->header('X-XSS-Protection',           '1; mode=block')
            ->header('Referrer-Policy',            'strict-origin-when-cross-origin')
            ->header('Permissions-Policy',         'camera=(), microphone=(), geolocation=()')
            ->header('Strict-Transport-Security',  'max-age=31536000; includeSubDomains')
            ->header('Content-Security-Policy',
                "default-src 'self'; " .
                "script-src 'self' 'unsafe-inline' https://stun.l.google.com; " .
                "style-src 'self' 'unsafe-inline'; " .
                "img-src 'self' data: blob:; " .
                "media-src 'self' blob:; " .
                "connect-src 'self' wss: https:; " .
                "frame-ancestors 'none';"
            );
    }
}