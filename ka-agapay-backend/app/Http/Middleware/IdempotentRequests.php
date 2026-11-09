<?php
// app/Http/Middleware/IdempotentRequests.php
//
// A REQUEST SENT TWICE IS DONE ONCE.
//
// The admin and the app keep what staff and residents save while the
// connection is down, and send it when it comes back (offlineOutbox on both).
// A request can also reach the server and do its work while the answer is
// lost on the way back: the client cannot tell that from one that never
// arrived, so it sends it again. Each such request carries an
// Idempotency-Key. The first answer for a key is kept for a day and a repeat
// gets that answer back, instead of a second walk-in, booking or queue ticket.
//
// Only signed-in requests with a JSON body, and only answers below 500, are
// kept: a server error may succeed on the next try. A key is the user's own;
// another account sending the same key starts afresh.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class IdempotentRequests
{
    public const HEADER = 'Idempotency-Key';

    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header(self::HEADER, '');

        if ($key === '' || !in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        if (!preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key)) {
            return response()->json(['message' => 'The Idempotency-Key header is not valid.'], 400);
        }

        // The route's own auth:sanctum runs after this; the guard reads the
        // same bearer token here.
        $user = $request->user('sanctum');

        if (!$user || str_starts_with(strtolower((string) $request->header('Content-Type')), 'multipart/')) {
            return $next($request);
        }

        $scope = 'idempotency:' . $user->getAuthIdentifier() . ':' . hash('sha256', $key);
        $fingerprint = hash('sha256', $request->method() . ' ' . $request->path() . ' ' . $request->getContent());

        if ($stored = Cache::get($scope)) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = Cache::lock($scope . ':lock', 60);

        if (!$lock->get()) {
            return response()->json(['message' => 'This request is already being processed. Try again in a moment.'], 409);
        }

        try {
            if ($stored = Cache::get($scope)) {
                return $this->replay($stored, $fingerprint);
            }

            $response = $next($request);
            $type = (string) $response->headers->get('Content-Type');

            if ($response->getStatusCode() < 500 && str_contains($type, 'json')) {
                Cache::put($scope, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'type' => $type,
                    'body' => (string) $response->getContent(),
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function replay(array $stored, string $fingerprint): Response
    {
        if (!hash_equals($stored['fingerprint'], $fingerprint)) {
            return response()->json([
                'message' => 'This Idempotency-Key was already used for a different request.',
            ], 422);
        }

        return response($stored['body'], $stored['status'], [
            'Content-Type' => $stored['type'],
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
