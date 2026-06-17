<?php
// app/Http/Controllers/Api/AdminUserController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        'municipal_mayor',
        'it_staff',
        'super_admin',
        'superadmin',
    ];

    private array $approverRoles = [
        'mho',
        'municipal_mayor',
        'it_staff',
        'super_admin',
        'superadmin',
    ];

    private array $staffRoles = [
        'doctor',
        'nurse',
        'midwife',
        'bhw',
        'staff',
        'staff_admin',
        'admin',
        'rhu_admin',
        'mho',
        'municipal_mayor',
        'it_staff',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $roleColumns = $this->roleNameColumns();

        $users = User::with('role')
            ->when($request->filled('search'), function ($query) use ($request, $roleColumns) {
                $search = trim((string) $request->search);

                $query->where(function ($inner) use ($search, $roleColumns) {
                    $inner->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('mobile_number', 'ilike', "%{$search}%")
                        ->orWhere('barangay', 'ilike', "%{$search}%");

                    if (!empty($roleColumns)) {
                        $inner->orWhereHas('role', function ($roleQuery) use ($search, $roleColumns) {
                            $roleQuery->where(function ($q) use ($search, $roleColumns) {
                                foreach ($roleColumns as $column) {
                                    $q->orWhere($column, 'ilike', "%{$search}%");
                                }
                            });
                        });
                    }
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('account_status', $request->status);
            })
            ->when($request->filled('role'), function ($query) use ($request, $roleColumns) {
                $role = $this->normalizeRoleName((string) $request->role);

                $query->whereHas('role', function ($roleQuery) use ($role, $roleColumns) {
                    $roleQuery->where(function ($q) use ($role, $roleColumns) {
                        foreach ($roleColumns as $column) {
                            $q->orWhereRaw("LOWER(REPLACE(REPLACE({$column}, ' ', '_'), '-', '_')) = ?", [$role]);
                        }

                        if (empty($roleColumns)) {
                            $q->whereRaw('1 = 0');
                        }
                    });
                });
            })
            ->latest('created_at')
            ->paginate($request->integer('per_page', 20));

        $users->getCollection()->transform(fn (User $user) => $this->userPayload($user));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'mobile_number' => ['nullable', 'string', 'max:20', 'unique:users,mobile_number'],
            'phone' => ['nullable', 'string', 'max:20'],
            'barangay' => ['nullable', 'string', 'max:150'],
            'role' => ['required', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'min:8'],
            'account_status' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $roleName = $this->normalizeRoleName($validated['role']);
        abort_unless(in_array($roleName, $this->allowedRoles, true), 422, 'Invalid role.');

        $this->authorizeRoleAssignment($request, $roleName);

        $roleId = $this->resolveRoleId($roleName);
        abort_unless($roleId, 422, "Role '{$roleName}' was not found in user_roles table.");

        [$firstName, $lastName] = $this->resolveNameParts(
            $validated['name'] ?? null,
            $validated['first_name'] ?? null,
            $validated['last_name'] ?? null
        );

        abort_if(trim($firstName) === '', 422, 'First name is required.');
        abort_if(empty($validated['mobile_number'] ?? $validated['phone'] ?? null), 422, 'Mobile number is required.');

        $status = $validated['account_status'] ?? $validated['status'] ?? 'active';

        $user = User::create([
            'role_id' => $roleId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $validated['email'] ?? null,
            'mobile_number' => $validated['mobile_number'] ?? $validated['phone'] ?? null,
            'barangay' => $validated['barangay'] ?? null,
            'password' => Hash::make($validated['password'] ?? 'KaAgapay@1234'),
            'account_status' => $status,
            'id_verified' => true,
            'staff_approved_by' => $request->user()?->user_id,
            'staff_approved_at' => in_array($roleName, $this->staffRoles, true) ? now() : null,
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $this->userPayload($user->fresh()->load('role')),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $user = User::with('role')->findOrFail($id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => [
                'nullable',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id'),
            ],
            'mobile_number' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('users', 'mobile_number')->ignore($user->user_id, 'user_id'),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'barangay' => ['nullable', 'string', 'max:150'],
            'role' => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'min:8'],
            'account_status' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $updates = [];

        if (
            array_key_exists('name', $validated) ||
            array_key_exists('first_name', $validated) ||
            array_key_exists('last_name', $validated)
        ) {
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

            $this->authorizeRoleAssignment($request, $roleName);

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
            'data' => $this->userPayload($user->fresh()->load('role')),
        ]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive,pending,suspended,rejected'],
        ]);

        $user = User::findOrFail($id);
        $user->update(['account_status' => $validated['status']]);

        return response()->json([
            'message' => 'User status updated.',
            'data' => $this->userPayload($user->fresh()->load('role')),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeStaffApprover($request);

        $user = User::with('role')->findOrFail($id);
        $roleName = $this->normalizeRoleName($user->role_name);

        if (in_array($roleName, $this->staffRoles, true)) {
            abort_unless((bool) $user->id_verified, 422, 'Cannot approve staff account until OCR/name verification is approved.');
        }

        $user->update([
            'account_status' => 'active',
            'staff_approved_by' => $request->user()?->user_id,
            'staff_approved_at' => now(),
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'User approved successfully.',
            'data' => $this->userPayload($user->fresh()->load('role')),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeStaffApprover($request);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::findOrFail($id);

        $user->update([
            'account_status' => 'rejected',
            'rejection_reason' => $validated['reason'] ?? 'Rejected by approver.',
        ]);

        return response()->json([
            'message' => 'User rejected.',
            'data' => $this->userPayload($user->fresh()->load('role')),
        ]);
    }

    public function assignRole(Request $request, int $id): JsonResponse
    {
        $this->authorizeStaffApprover($request);

        $validated = $request->validate([
            'role' => ['required', 'string', 'max:50'],
        ]);

        $roleName = $this->normalizeRoleName($validated['role']);
        abort_unless(in_array($roleName, $this->allowedRoles, true), 422, 'Invalid role.');

        $roleId = $this->resolveRoleId($roleName);
        abort_unless($roleId, 422, "Role '{$roleName}' was not found.");

        $user = User::findOrFail($id);
        $user->update(['role_id' => $roleId]);

        return response()->json([
            'message' => 'User role updated.',
            'data' => $this->userPayload($user->fresh()->load('role')),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $user = User::findOrFail($id);
        $user->update(['account_status' => 'inactive']);

        return response()->json([
            'message' => 'User deactivated.',
            'data' => $this->userPayload($user->fresh()->load('role')),
        ]);
    }

    private function authorizeUserManagement(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole($this->approverRoles),
            403,
            'Only MHO, Municipal Mayor, IT Staff, or Super Admin can manage users.'
        );
    }

    private function authorizeStaffApprover(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole($this->approverRoles),
            403,
            'Only MHO, Municipal Mayor, IT Staff, or Super Admin can approve staff accounts.'
        );
    }

    private function authorizeRoleAssignment(Request $request, string $roleName): void
    {
        if (in_array($roleName, $this->staffRoles, true)) {
            $this->authorizeStaffApprover($request);
        }
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

    private function resolveRoleId(string $roleName): ?int
    {
        $normalized = $this->normalizeRoleName($roleName);

        $roles = UserRole::query()->get();

        foreach ($roles as $role) {
            foreach (['name', 'role_name', 'slug', 'role', 'title', 'code'] as $field) {
                if (isset($role->{$field}) && $this->normalizeRoleName((string) $role->{$field}) === $normalized) {
                    return (int) $role->role_id;
                }
            }
        }

        return null;
    }

    private function roleNameColumns(): array
    {
        if (!Schema::hasTable('user_roles')) {
            return [];
        }

        $existing = Schema::getColumnListing('user_roles');

        return array_values(array_intersect(
            ['name', 'role_name', 'slug', 'role', 'title', 'code'],
            $existing
        ));
    }

    private function normalizeRoleName(string $role): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($role)));
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('role');

        return [
            'id' => $user->user_id,
            'user_id' => $user->user_id,
            'name' => $user->full_name,
            'full_name' => $user->full_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->mobile_number,
            'mobile_number' => $user->mobile_number,
            'role' => $this->normalizeRoleName($user->role_name),
            'role_id' => $user->role_id,
            'status' => $user->account_status,
            'account_status' => $user->account_status,
            'barangay' => $user->barangay,
            'id_verified' => (bool) $user->id_verified,
            'staff_approved_by' => $user->staff_approved_by,
            'staff_approved_at' => optional($user->staff_approved_at)->toISOString(),
            'rejection_reason' => $user->rejection_reason,
            'capabilities' => $user->capabilities,
            'created_at' => optional($user->created_at)->toISOString(),
            'updated_at' => optional($user->updated_at)->toISOString(),
        ];
    }
}