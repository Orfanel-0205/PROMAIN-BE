<?php
// app/Http/Middleware/RoleMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Example:
     * Route::middleware('role:admin,staff,rhu_admin,super_admin')->group(...)
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user->loadMissing('role');

        $roleName = null;

        if ($user->role && is_object($user->role)) {
            $roleName = $user->role->name
                ?? $user->role->role_name
                ?? null;
        }

        $roleName = $roleName
            ?? $user->role_name
            ?? $user->user_role
            ?? $user->account_type
            ?? null;

        $roleName = strtolower(trim((string) $roleName));

        $allowedRoles = collect($roles)
            ->map(fn ($role) => strtolower(trim((string) $role)))
            ->filter()
            ->values()
            ->all();

        if (!in_array($roleName, $allowedRoles, true)) {
            return response()->json([
                'message' => 'Forbidden. Your role is not allowed to access this resource.',
                'current_role' => $roleName ?: null,
                'required_roles' => $allowedRoles,
            ], 403);
        }

        return $next($request);
    }
}