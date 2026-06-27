<?php
// app/Http/Middleware/CheckAccountStatus.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Blocks NON-active accounts from protected features.
 *
 * FINAL RULE: pending registrants may only reach the endpoints they need to
 * COMPLETE their registration (submit ID/OCR, read/refresh their own profile,
 * log out, register a push token). Everything else — appointments, queue,
 * consultations, dashboard, etc. — is blocked until a Super Admin approves the
 * account. Rejected/suspended/inactive accounts are blocked entirely and shown
 * the reason. Active accounts pass through untouched.
 */
class CheckAccountStatus
{
    /**
     * Endpoints a PENDING / under_review user may still call so they can finish
     * the registration + ID-verification flow. Matched with Request::is().
     */
    private array $allowedWhilePending = [
        'api/v1/ocr',
        'api/v1/ocr/*',
        'api/v1/me',
        'api/v1/user',
        'api/v1/logout',
        'api/v1/profile',
        'api/v1/profile/*',
        'api/v1/change-password',
        'api/v1/barangays',
        'api/v1/biometric/*',
        'api/v1/notifications/device-token',
        'api/v1/logs',
        'api/v1/activity-logs',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        // Account locked due to failed logins.
        if ($user->locked_until && now()->isBefore($user->locked_until)) {
            $minutesLeft = now()->diffInMinutes($user->locked_until);

            return response()->json([
                'message'      => "Account temporarily locked. Try again in {$minutesLeft} minutes.",
                'locked_until' => $user->locked_until,
            ], 423);
        }

        $status = strtolower((string) $user->account_status);

        if ($status === 'active') {
            return $next($request);
        }

        // Pending / under review: allow only the registration-completion routes.
        if (in_array($status, ['pending', 'under_review'], true)) {
            if ($request->is(...$this->allowedWhilePending)) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Your account is pending Super Admin approval. Please complete your ID verification and wait for review.',
                'status'  => 'pending',
            ], 403);
        }

        if ($status === 'rejected') {
            $reason = trim((string) $user->rejection_reason);

            return response()->json([
                'message'          => $reason !== ''
                    ? 'Your registration was rejected. Reason: ' . $reason
                    : 'Your registration was rejected. Please contact the RHU.',
                'status'           => 'rejected',
                'rejection_reason' => $reason !== '' ? $reason : null,
            ], 403);
        }

        if ($status === 'suspended') {
            return response()->json([
                'message' => 'Your account has been suspended. Please contact the RHU.',
                'status'  => 'suspended',
            ], 403);
        }

        // inactive / unknown
        return response()->json([
            'message' => 'Your account is not active. Please contact the RHU.',
            'status'  => $status ?: 'inactive',
        ], 403);
    }
}
