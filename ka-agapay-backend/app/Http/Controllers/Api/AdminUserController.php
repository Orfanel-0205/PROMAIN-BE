<?php
// app/Http/Controllers/Api/AdminUserController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    private array $allowedRoles = [
        'patient',
        'resident',
        'doctor',
        'nurse',
        'midwife',
        'bhw',
        'staff',
        'staff_admin',
        'admin',
        'rhu_admin',
        'mho',
        'super_admin',
        'superadmin',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'search'   => ['nullable', 'string', 'max:100'],
            'role'     => ['nullable', 'string', 'max:50'],
            'status'   => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $users = User::with('role')
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim((string) $request->search);

                $q->where(function ($inner) use ($search) {
                    $inner->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('mobile_number', 'ilike', "%{$search}%")
                        ->orWhere('barangay', 'ilike', "%{$search}%");
                });
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('account_status', $request->status);
            })
            ->when($request->filled('role'), function ($q) use ($request) {
                $role = $this->normalizeRoleName((string) $request->role);

                $q->whereHas('role', function ($roleQuery) use ($role) {
                    $roleQuery->whereRaw("LOWER(REPLACE(COALESCE(role_name, name, slug, role, title, code, ''), ' ', '_')) = ?", [$role]);
                });
            })
            ->latest('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name'           => ['nullable', 'string', 'max:150'],
            'first_name'     => ['nullable', 'string', 'max:100'],
            'last_name'      => ['nullable', 'string', 'max:100'],
            'email'          => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'mobile_number'  => ['nullable', 'string', 'max:20', 'unique:users,mobile_number'],
            'phone'          => ['nullable', 'string', 'max:20'],
            'barangay'       => ['nullable', 'string', 'max:150'],
            'role'           => ['required', 'string', 'max:50'],
            'password'       => ['nullable', 'string', 'min:8'],
            'account_status' => ['nullable', 'string', 'max:30'],
            'status'         => ['nullable', 'string', 'max:30'],
        ]);

        $roleName = $this->normalizeRoleName($validated['role']);
        abort_unless(in_array($roleName, $this->allowedRoles, true), 422, 'Invalid role.');

        $roleId = $this->resolveRoleId($roleName);
        abort_unless($roleId, 422, "Role '{$roleName}' was not found in user_roles table.");

        [$firstName, $lastName] = $this->resolveNameParts(
            $validated['name'] ?? null,
            $validated['first_name'] ?? null,
            $validated['last_name'] ?? null
        );

        $user = User::create([
            'role_id'        => $roleId,
            'first_name'     => $firstName,
            'last_name'      => $lastName,
            'email'          => $validated['email'] ?? null,
            'mobile_number'  => $validated['mobile_number'] ?? $validated['phone'] ?? null,
            'barangay'       => $validated['barangay'] ?? null,
            'password'       => Hash::make($validated['password'] ?? 'KaAgapay@1234'),
            'account_status' => $validated['account_status'] ?? $validated['status'] ?? 'active',
            'id_verified'    => true,
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $user->load('role'),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name'           => ['nullable', 'string', 'max:150'],
            'first_name'     => ['nullable', 'string', 'max:100'],
            'last_name'      => ['nullable', 'string', 'max:100'],
            'email'          => [
                'nullable',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id'),
            ],
            'mobile_number'  => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('users', 'mobile_number')->ignore($user->user_id, 'user_id'),
            ],
            'phone'          => ['nullable', 'string', 'max:20'],
            'barangay'       => ['nullable', 'string', 'max:150'],
            'role'           => ['nullable', 'string', 'max:50'],
            'password'       => ['nullable', 'string', 'min:8'],
            'account_status' => ['nullable', 'string', 'max:30'],
            'status'         => ['nullable', 'string', 'max:30'],
        ]);

        $updates = [];

        if (array_key_exists('name', $validated) || array_key_exists('first_name', $validated) || array_key_exists('last_name', $validated)) {
            [$firstName, $lastName] = $this->resolveNameParts(
                $validated['name'] ?? null,
                $validated['first_name'] ?? $user->first_name,
                $validated['last_name'] ?? $user->last_name
            );

            $updates['first_name'] = $firstName;
            $updates['last_name'] = $lastName;
        }

        if (array_key_exists('email', $validated)) {
            $updates['email'] = $validated['email'];
        }

        if (array_key_exists('mobile_number', $validated) || array_key_exists('phone', $validated)) {
            $updates['mobile_number'] = $validated['mobile_number'] ?? $validated['phone'] ?? $user->mobile_number;
        }

        if (array_key_exists('barangay', $validated)) {
            $updates['barangay'] = $validated['barangay'];
        }

        if (!empty($validated['password'])) {
            $updates['password'] = Hash::make($validated['password']);
        }

        if (!empty($validated['role'])) {
            $roleName = $this->normalizeRoleName($validated['role']);
            abort_unless(in_array($roleName, $this->allowedRoles, true), 422, 'Invalid role.');

            $roleId = $this->resolveRoleId($roleName);
            abort_unless($roleId, 422, "Role '{$roleName}' was not found in user_roles table.");

            $updates['role_id'] = $roleId;
        }

        if (array_key_exists('account_status', $validated) || array_key_exists('status', $validated)) {
            $updates['account_status'] = $validated['account_status'] ?? $validated['status'];
        }

        $user->update($updates);

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $user->fresh()->load('role'),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::findOrFail($id);

        $user->update([
            'account_status' => 'inactive',
        ]);

        return response()->json([
            'message' => 'User deactivated.',
        ]);
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::findOrFail($id);
        $user->update(['account_status' => 'suspended']);

        return response()->json([
            'message' => 'User suspended.',
            'data' => $user->fresh()->load('role'),
        ]);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::findOrFail($id);
        $user->update(['account_status' => 'active']);

        return response()->json([
            'message' => 'User activated.',
            'data' => $user->fresh()->load('role'),
        ]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive,pending,suspended'],
        ]);

        $user = User::findOrFail($id);
        $user->update(['account_status' => $validated['status']]);

        return response()->json([
            'message' => 'User status updated.',
            'data' => $user->fresh()->load('role'),
        ]);
    }

    public function assignRole(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'role' => ['required', 'string', 'max:50'],
        ]);

        $roleName = $this->normalizeRoleName($validated['role']);
        $roleId = $this->resolveRoleId($roleName);

        abort_unless($roleId, 422, "Role '{$roleName}' was not found.");

        $user = User::findOrFail($id);
        $user->update(['role_id' => $roleId]);

        return response()->json([
            'message' => 'User role updated.',
            'data' => $user->fresh()->load('role'),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole(['mho', 'super_admin', 'admin', 'rhu_admin', 'staff_admin', 'staff']),
            403,
            'You are not allowed to manage users.'
        );
    }

    private function resolveNameParts(?string $name, ?string $firstName, ?string $lastName): array
    {
        if ($firstName || $lastName) {
            return [
                trim((string) $firstName),
                trim((string) $lastName),
            ];
        }

        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $first = array_shift($parts) ?: '';
        $last = implode(' ', $parts);

        return [$first, $last];
    }

    private function normalizeRoleName(string $role): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($role)));
    }

    private function resolveRoleId(string $roleName): ?int
    {
        if (!Schema::hasTable('user_roles')) {
            return null;
        }

        $columns = ['role_name', 'name', 'slug', 'role', 'title', 'code'];

        foreach ($columns as $column) {
            if (!Schema::hasColumn('user_roles', $column)) {
                continue;
            }

            $role = DB::table('user_roles')
                ->whereRaw("LOWER(REPLACE({$column}, ' ', '_')) = ?", [$roleName])
                ->first();

            if ($role) {
                return (int) ($role->role_id ?? $role->id);
            }
        }

        return null;
    }
}