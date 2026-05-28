<?php
// app/Http/Middleware/RoleMiddleware.php
// Enforces role-based access control at the route/middleware level.
//
// Usage in routes:
//   Route::middleware(['auth:sanctum', 'role:doctor,nurse'])->group(...)
//   Route::middleware(['auth:sanctum', 'role:super_admin'])->group(...)
//
// The middleware accepts ONE OR MORE comma-separated roles.
// The authenticated user must have AT LEAST ONE of the specified roles.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * @param  string  $roles  Comma-separated list of allowed role names
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Must be authenticated first (auth:sanctum handles this, but be defensive)
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Load role relationship if not already eager-loaded
        $roleName = $user->role?->name ?? $user->relationLoaded('role')
            ? $user->role?->name
            : $user->load('role')->role?->name;

        // Super admin bypasses all role checks
        if ($roleName === 'super_admin') {
            return $next($request);
        }

        // Check if user's role is in the allowed list
        if (in_array($roleName, $roles, true)) {
            return $next($request);
        }

        // Log unauthorised access attempts for audit
        try {
            \App\Models\ActivityLog::create([
                'user_id'    => $user->user_id,
                'action'     => 'UNAUTHORISED_ACCESS',
                'module'     => 'security',
                'metadata'   => [
                    'required_roles' => $roles,
                    'user_role'      => $roleName,
                    'path'           => $request->path(),
                ],
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable) {}

        return response()->json([
            'message'       => 'Forbidden. Insufficient permissions.',
            'required_role' => $roles,
            'your_role'     => $roleName,
        ], 403);
    }
}