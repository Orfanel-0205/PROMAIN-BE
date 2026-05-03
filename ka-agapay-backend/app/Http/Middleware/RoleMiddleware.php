<?php
// app/Http/Middleware/RoleMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    // Role hierarchy — higher number = more access
    private array $hierarchy = [
        'resident'    => 1,
        'bhw'         => 2,
        'nurse'       => 3,
        'doctor'      => 4,
        'it_staff'    => 4,
        'mho_admin'   => 5,
        'super_admin' => 6,
    ];

    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->account_status !== 'active') {
            return response()->json([
                'message' => 'Account is not active.',
                'status'  => $user->account_status,
            ], 403);
        }

        $userRole = $user->role?->name;

        // Super admin bypasses all role checks
        if ($userRole === 'super_admin') {
            return $next($request);
        }

        if (!in_array($userRole, $roles)) {
            return response()->json([
                'message' => "Access denied. Required role: " . implode(' or ', $roles),
            ], 403);
        }

        return $next($request);
    }
}