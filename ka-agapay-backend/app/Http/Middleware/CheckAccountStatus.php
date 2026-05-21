<?php
// app/Http/Middleware/CheckAccountStatus.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckAccountStatus
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (!$user) return $next($request);

        // Check if account is locked due to failed logins
        if ($user->locked_until && now()->isBefore($user->locked_until)) {
            $minutesLeft = now()->diffInMinutes($user->locked_until);
            return response()->json([
                'message' => "Account temporarily locked. Try again in {$minutesLeft} minutes.",
                'locked_until' => $user->locked_until,
            ], 423);
        }

        if ($user->account_status === 'pending') {
            return response()->json([
                'message' => 'Your registration is pending review by RHU staff.',
                'status'  => 'pending',
            ], 403);
        }

        if ($user->account_status === 'under_review') {
            return response()->json([
                'message' => 'Your registration is currently being reviewed. Please wait.',
                'status'  => 'under_review',
            ], 403);
        }

        if ($user->account_status === 'rejected') {
            return response()->json([
                'message' => 'Your registration was rejected. Please contact RHU staff.',
                'status'  => 'rejected',
            ], 403);
        }

        if ($user->account_status === 'suspended') {
            return response()->json([
                'message' => 'Your account has been suspended.',
                'status'  => 'suspended',
            ], 403);
        }

        return $next($request);
    }
}