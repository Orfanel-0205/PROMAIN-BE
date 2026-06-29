<?php
// app/Http/Controllers/Api/AdminUserController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /*
     * These roles can manage normal users.
     * Protected management accounts are still guarded by $protectedRoles.
     */
    private array $approverRoles = [
        'admin',
        'staff_admin',
        'rhu_admin',
        'mho',
        'municipal_mayor',
        'it_staff',
        'super_admin',
        'superadmin',
    ];

    /*
     * Only Super Admin can modify these protected accounts.
     */
    private array $protectedRoles = [
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
        'super_admin',
        'superadmin',
    ];

    private array $statusValues = [
        'pending',
        'active',
        'inactive',
        'suspended',
        'rejected',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $roleColumns = $this->roleNameColumns();
        $like = $this->likeOperator();

        $users = User::with('role')
            ->when(!empty($validated['search']), function ($query) use ($validated, $roleColumns, $like) {
                $search = trim((string) $validated['search']);

                $query->where(function ($inner) use ($search, $roleColumns, $like) {
                    $inner->where('first_name', $like, "%{$search}%")
                        ->orWhere('last_name', $like, "%{$search}%")
                        ->orWhere('email', $like, "%{$search}%")
                        ->orWhere('mobile_number', $like, "%{$search}%")
                        ->orWhere('barangay', $like, "%{$search}%");

                    if (!empty($roleColumns)) {
                        $inner->orWhereHas('role', function ($roleQuery) use ($search, $roleColumns, $like) {
                            $roleQuery->where(function ($q) use ($search, $roleColumns, $like) {
                                foreach ($roleColumns as $column) {
                                    $q->orWhere($column, $like, "%{$search}%");
                                }
                            });
                        });
                    }
                });
            })
            ->when(!empty($validated['status']), function ($query) use ($validated) {
                $status = $this->normalizeStatus((string) $validated['status']);

                if (in_array($status, $this->statusValues, true)) {
                    $query->where('account_status', $status);
                }
            })
            ->when(!empty($validated['role']), function ($query) use ($validated, $roleColumns) {
                $role = $this->normalizeRoleName((string) $validated['role']);

                $query->whereHas('role', function ($roleQuery) use ($role, $roleColumns) {
                    $roleQuery->where(function ($q) use ($role, $roleColumns) {
                        foreach ($roleColumns as $column) {
                            $q->orWhereRaw(
                                "LOWER(REPLACE(REPLACE({$column}, ' ', '_'), '-', '_')) = ?",
                                [$role]
                            );
                        }

                        if (empty($roleColumns)) {
                            $q->whereRaw('1 = 0');
                        }
                    });
                });
            })
            ->latest('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        $users->getCollection()->transform(
            fn (User $user) => $this->userPayload($user)
        );

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],

            'email' => ['nullable', 'email', 'max:150'],

            'mobile_number' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],

            'barangay' => ['nullable', 'string', 'max:150'],

            'role' => ['required', 'string', 'max:50'],

            'password' => ['nullable', 'string', 'min:8', 'max:100'],

            'sex' => ['nullable', 'string', 'max:30'],
            'birthdate' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:500'],
            'guardian_name' => ['nullable', 'string', 'max:150'],
            'philhealth_number' => ['nullable', 'string', 'max:50'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'past_medical_history' => ['nullable', 'string', 'max:2000'],
            'maintenance_medications' => ['nullable', 'string', 'max:2000'],
            'family_history' => ['nullable', 'string', 'max:2000'],
            'personal_social_history' => ['nullable', 'string', 'max:2000'],

            'account_status' => ['nullable', 'string', Rule::in($this->statusValues)],
            'status' => ['nullable', 'string', Rule::in($this->statusValues)],
        ]);

        $roleName = $this->normalizeRoleName((string) $validated['role']);
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

        $mobile = $this->normalizeMobileNumber(
            $validated['mobile_number'] ?? $validated['phone'] ?? null
        );

        abort_if($mobile === '', 422, 'Mobile number is required.');
        abort_unless($this->isValidPhilippineMobile($mobile), 422, 'Mobile number must use this format: 09XXXXXXXXX.');

        $email = $this->normalizeEmail($validated['email'] ?? null);

        // Check duplicates INCLUDING soft-deleted rows. The Postgres unique
        // constraints (users_mobile_number_unique / users_email_unique) still
        // count soft-deleted accounts, so without withTrashed() a previously
        // deleted patient would slip past this check and crash on INSERT (23505).
        $duplicateErrors = [];

        if ($email !== null && User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $duplicateErrors['email'] = ['Email is already registered.'];
        }

        if (User::withTrashed()->where('mobile_number', $mobile)->exists()) {
            $duplicateErrors['mobile_number'] = ['Mobile number is already registered.'];
        }

        if (!empty($duplicateErrors)) {
            throw ValidationException::withMessages($duplicateErrors);
        }

        $status = $this->normalizeStatus(
            (string) ($validated['account_status'] ?? $validated['status'] ?? '')
        );

        if ($status === '') {
            $status = $this->isResidentRole($roleName) ? 'active' : 'pending';
        }

        if ($status === 'active' && $this->isStaffRole($roleName)) {
            $this->authorizeStaffApprover($request);
        }

        try {
            $user = DB::transaction(function () use (
                $request,
                $validated,
                $roleId,
                $roleName,
                $firstName,
                $lastName,
                $email,
                $mobile,
                $status
            ) {
                $user = User::create([
                    'role_id' => $roleId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'mobile_number' => $mobile,
                    'barangay' => $validated['barangay'] ?? null,
                    'password' => Hash::make($validated['password'] ?? 'KaAgapay@1234'),
                    'account_status' => $status,

                    /*
                     * Admin-created accounts are treated as verified records.
                     * Staff still become pending unless status was explicitly active.
                     */
                    'id_verified' => true,

                    'staff_approved_by' => $status === 'active' && $this->isStaffRole($roleName)
                        ? $request->user()?->user_id
                        : null,
                    'staff_approved_at' => $status === 'active' && $this->isStaffRole($roleName)
                        ? now()
                        : null,
                    'rejection_reason' => null,
                ]);

                if ($this->isResidentRole($roleName)) {
                    $this->persistUserPatientFields($user, $validated);
                    $this->persistResidentProfileFields($user, $validated, $firstName, $lastName, $mobile);
                }

                return $user;
            });
        } catch (QueryException $e) {
            // Safety net for any unique-constraint race that slips past the
            // pre-checks above — never let a 23505 escape as a 500.
            return $this->handleUserUniqueViolation($e);
        }

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $this->userPayload($user->fresh()->load('role')),
        ], 201);
    }

    /**
     * GET /admin/users/roles
     * Safe role list for the Web Admin "Add Patient / Mobile Account" modal.
     * Returns only the roles the admin UI needs — no secrets.
     */
    public function roles(Request $request): JsonResponse
    {
        $this->authorizeUserManagement($request);

        if (!Schema::hasTable('user_roles')) {
            return response()->json(['data' => []]);
        }

        $columns = Schema::getColumnListing('user_roles');
        $hasLabel = in_array('label', $columns, true);
        $hasDescription = in_array('description', $columns, true);

        // Only expose the roles the Web Admin actually uses.
        $allowed = [
            'resident', 'patient', 'staff', 'nurse', 'midwife', 'bhw',
            'doctor', 'mho', 'rhu_admin', 'super_admin',
        ];

        $roles = UserRole::query()->get()
            ->map(function (UserRole $role) use ($hasLabel, $hasDescription) {
                $name = $this->normalizeRoleName(
                    (string) ($role->name ?? $role->role_name ?? $role->slug ?? $role->role ?? '')
                );

                return [
                    'role_id' => (int) $role->role_id,
                    'name' => $name,
                    'label' => $hasLabel && !empty($role->label)
                        ? $role->label
                        : ucwords(str_replace('_', ' ', $name)),
                    'description' => $hasDescription ? ($role->description ?? null) : null,
                ];
            })
            ->filter(fn (array $role) => $role['role_id'] > 0
                && in_array($role['name'], $allowed, true))
            ->values();

        return response()->json(['data' => $roles]);
    }

    /**
     * Map a Postgres unique-violation (SQLSTATE 23505) to a clean 422 JSON
     * response so a duplicate mobile/email never escapes as a 500.
     */
    private function handleUserUniqueViolation(QueryException $e): JsonResponse
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $message = $e->getMessage();

        if ($sqlState === '23505' || str_contains($message, '23505')) {
            if (str_contains($message, 'users_mobile_number_unique')) {
                return response()->json([
                    'message' => 'Mobile number is already registered.',
                    'errors' => ['mobile_number' => ['Mobile number is already registered.']],
                ], 422);
            }

            if (str_contains($message, 'users_email_unique')) {
                return response()->json([
                    'message' => 'Email is already registered.',
                    'errors' => ['email' => ['Email is already registered.']],
                ], 422);
            }

            return response()->json([
                'message' => 'This account already exists.',
                'errors' => ['mobile_number' => ['This account already exists.']],
            ], 422);
        }

        // Check-constraint violation (e.g. users_sex_check) → clean 422.
        if ($sqlState === '23514' || str_contains($message, '23514')) {
            if (str_contains($message, 'users_sex_check')) {
                return response()->json([
                    'message' => 'Invalid sex/gender value.',
                    'errors' => ['sex' => ['Invalid sex/gender value.']],
                ], 422);
            }

            return response()->json([
                'message' => 'One of the submitted values is not allowed.',
            ], 422);
        }

        Log::error('[AdminUserController] Database error while creating user.', [
            'sql_state' => $sqlState,
            'error' => $message,
        ]);

        return response()->json([
            'message' => 'Could not create the account due to a server error. Please try again.',
        ], 500);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $user = User::with('role')->findOrFail($id);
        $currentRole = $this->normalizeRoleName($user->role_name);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],

            'email' => ['nullable', 'email', 'max:150'],

            'mobile_number' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],

            'barangay' => ['nullable', 'string', 'max:150'],

            'role' => ['nullable', 'string', 'max:50'],

            'password' => ['nullable', 'string', 'min:8', 'max:100'],

            'sex' => ['nullable', 'string', 'max:30'],
            'birthdate' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:500'],
            'guardian_name' => ['nullable', 'string', 'max:150'],
            'philhealth_number' => ['nullable', 'string', 'max:50'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'past_medical_history' => ['nullable', 'string', 'max:2000'],
            'maintenance_medications' => ['nullable', 'string', 'max:2000'],
            'family_history' => ['nullable', 'string', 'max:2000'],
            'personal_social_history' => ['nullable', 'string', 'max:2000'],

            'account_status' => ['nullable', 'string', Rule::in($this->statusValues)],
            'status' => ['nullable', 'string', Rule::in($this->statusValues)],

            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updates = [];
        $newRole = $currentRole;

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

            abort_if(trim($firstName) === '', 422, 'First name is required.');

            $updates['first_name'] = $firstName;
            $updates['last_name'] = $lastName;
        }

        if (array_key_exists('email', $validated)) {
            $email = $this->normalizeEmail($validated['email'] ?? null);

            if ($email !== null) {
                abort_if(
                    User::withTrashed()
                        ->whereRaw('LOWER(email) = ?', [$email])
                        ->where('user_id', '!=', $user->user_id)
                        ->exists(),
                    422,
                    'Email is already registered.'
                );
            }

            $updates['email'] = $email;
        }

        if (array_key_exists('mobile_number', $validated) || array_key_exists('phone', $validated)) {
            $mobile = $this->normalizeMobileNumber(
                $validated['mobile_number'] ?? $validated['phone'] ?? null
            );

            abort_if($mobile === '', 422, 'Mobile number is required.');
            abort_unless($this->isValidPhilippineMobile($mobile), 422, 'Mobile number must use this format: 09XXXXXXXXX.');

            abort_if(
                User::withTrashed()
                    ->where('mobile_number', $mobile)
                    ->where('user_id', '!=', $user->user_id)
                    ->exists(),
                422,
                'Mobile number is already registered.'
            );

            $updates['mobile_number'] = $mobile;
        }

        if (array_key_exists('barangay', $validated)) {
            $updates['barangay'] = $validated['barangay'];
        }

        if (!empty($validated['password'])) {
            $updates['password'] = Hash::make($validated['password']);
        }

        if (!empty($validated['role'])) {
            $newRole = $this->normalizeRoleName((string) $validated['role']);

            abort_unless(in_array($newRole, $this->allowedRoles, true), 422, 'Invalid role.');

            $this->assertTargetCanBeChanged($request, $user);
            $this->authorizeRoleAssignment($request, $newRole);

            $roleId = $this->resolveRoleId($newRole);
            abort_unless($roleId, 422, "Role '{$newRole}' was not found in user_roles table.");

            $updates['role_id'] = $roleId;
        }

        if (array_key_exists('account_status', $validated) || array_key_exists('status', $validated)) {
            $newStatus = $this->normalizeStatus(
                (string) ($validated['account_status'] ?? $validated['status'])
            );

            abort_unless(in_array($newStatus, $this->statusValues, true), 422, 'Invalid status.');

            $this->assertSafeStatusChange($request, $user, $newStatus);

            if ($newStatus === 'active' && $this->isStaffRole($newRole)) {
                $updates['id_verified'] = true;
                $updates['staff_approved_by'] = $request->user()?->user_id;
                $updates['staff_approved_at'] = now();
                $updates['rejection_reason'] = null;
            }

            if (in_array($newStatus, ['rejected', 'suspended'], true)) {
                $updates['rejection_reason'] = $validated['reason'] ?? $user->rejection_reason;
            }

            if ($newStatus === 'inactive') {
                $updates['rejection_reason'] = $validated['reason'] ?? null;
            }

            $updates['account_status'] = $newStatus;
        }

        DB::transaction(function () use ($user, $updates, $validated, $newRole) {
            $user->update($updates);

            if ($this->isResidentRole($newRole)) {
                $this->persistUserPatientFields($user->fresh(), $validated);
                $this->persistResidentProfileFields(
                    $user->fresh(),
                    $validated,
                    $updates['first_name'] ?? $user->first_name,
                    $updates['last_name'] ?? $user->last_name,
                    $updates['mobile_number'] ?? $user->mobile_number
                );
            }
        });

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $this->userPayload($user->fresh()->load('role')),
        ]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in($this->statusValues)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::with('role')->findOrFail($id);
        $newStatus = $this->normalizeStatus((string) $validated['status']);

        $this->assertSafeStatusChange($request, $user, $newStatus);

        $updates = [
            'account_status' => $newStatus,
        ];

        $roleName = $this->normalizeRoleName($user->role_name);

        /*
         * FIX FOR I-ENABLE:
         * If an authorized admin activates a staff/admin account, it also approves
         * the ID verification and staff approval fields.
         */
        if ($newStatus === 'active' && $this->isStaffRole($roleName)) {
            $updates['id_verified'] = true;
            $updates['staff_approved_by'] = $request->user()?->user_id;
            $updates['staff_approved_at'] = now();
            $updates['rejection_reason'] = null;
        }

        if (in_array($newStatus, ['rejected', 'suspended'], true)) {
            $updates['rejection_reason'] = $validated['reason'] ?? 'Status changed by authorized admin.';
        }

        if ($newStatus === 'inactive') {
            $updates['rejection_reason'] = $validated['reason'] ?? null;
        }

        DB::transaction(function () use ($user, $updates) {
            $user->update($updates);
        });

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

        $this->assertTargetCanBeChanged($request, $user);

        DB::transaction(function () use ($request, $user, $roleName) {
            $updates = [
                'account_status' => 'active',
                'id_verified' => true,
                'rejection_reason' => null,
            ];

            if ($this->isStaffRole($roleName)) {
                $updates['staff_approved_by'] = $request->user()?->user_id;
                $updates['staff_approved_at'] = now();
            }

            $user->update($updates);
        });

        return response()->json([
            'message' => 'User approved successfully.',
            'data' => $this->userPayload($user->fresh()->load('role')),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeStaffApprover($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $user = User::with('role')->findOrFail($id);

        $this->assertTargetCanBeChanged($request, $user);

        DB::transaction(function () use ($user, $validated) {
            $user->update([
                'account_status' => 'rejected',
                'rejection_reason' => trim((string) $validated['reason']),
            ]);
        });

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

        $roleName = $this->normalizeRoleName((string) $validated['role']);

        abort_unless(in_array($roleName, $this->allowedRoles, true), 422, 'Invalid role.');

        $user = User::with('role')->findOrFail($id);

        $this->assertTargetCanBeChanged($request, $user);
        $this->authorizeRoleAssignment($request, $roleName);

        $roleId = $this->resolveRoleId($roleName);
        abort_unless($roleId, 422, "Role '{$roleName}' was not found.");

        DB::transaction(function () use ($user, $roleId) {
            $user->update(['role_id' => $roleId]);
        });

        return response()->json([
            'message' => 'User role updated.',
            'data' => $this->userPayload($user->fresh()->load('role')),
        ]);
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $request->merge([
            'status' => 'suspended',
            'reason' => $request->input('reason', 'User suspended by RHU admin.'),
        ]);

        return $this->status($request, $id);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        $request->merge([
            'status' => 'active',
            'reason' => $request->input('reason', 'User activated and verified by RHU admin.'),
        ]);

        return $this->status($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'delete_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::with('role')->findOrFail($id);

        $this->assertSafeStatusChange($request, $user, 'inactive');

        $reason = trim((string) (
            $validated['reason']
            ?? $validated['delete_reason']
            ?? 'Deleted from RHU admin user management.'
        ));

        $oldValues = $user->toArray();

        DB::transaction(function () use ($request, $user, $reason, $oldValues) {
            $updates = [
                'account_status' => 'inactive',
                'rejection_reason' => $reason,
            ];

            if (Schema::hasColumn('users', 'deleted_by')) {
                $updates['deleted_by'] = $request->user()?->user_id;
            }

            if (Schema::hasColumn('users', 'delete_reason')) {
                $updates['delete_reason'] = $reason;
            }

            $user->update($updates);
            $user->delete();

            $this->logUserDeleteHistory($request, $user, $oldValues, $reason);
        });

        return response()->json([
            'message' => 'User deleted safely. The account can be restored from Delete History.',
        ]);
    }

    private function authorizeUserManagement(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole($this->approverRoles),
            403,
            'Only authorized RHU admins can manage users.'
        );
    }

    private function authorizeStaffApprover(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole($this->approverRoles),
            403,
            'Only authorized RHU admins can approve staff accounts.'
        );
    }

    private function authorizeRoleAssignment(Request $request, string $roleName): void
    {
        if ($this->isProtectedRole($roleName)) {
            abort_unless(
                $this->currentUserIsSuperAdmin($request),
                403,
                'Only Super Admin can assign MHO, Municipal Mayor, IT Staff, or Super Admin roles.'
            );
        }

        if ($this->isStaffRole($roleName)) {
            $this->authorizeStaffApprover($request);
        }
    }

    private function assertTargetCanBeChanged(Request $request, User $target): void
    {
        $targetRole = $this->normalizeRoleName($target->role_name);

        if ((int) $request->user()?->user_id === (int) $target->user_id) {
            abort(422, 'You cannot change your own management role or approval status here.');
        }

        if ($this->isProtectedRole($targetRole) && !$this->currentUserIsSuperAdmin($request)) {
            abort(403, 'Only Super Admin can modify protected management accounts.');
        }
    }

    private function assertSafeStatusChange(Request $request, User $target, string $newStatus): void
    {
        if ((int) $request->user()?->user_id === (int) $target->user_id && $newStatus !== 'active') {
            abort(422, 'You cannot disable, suspend, reject, or delete your own account.');
        }

        $targetRole = $this->normalizeRoleName($target->role_name);

        if ($this->isProtectedRole($targetRole) && !$this->currentUserIsSuperAdmin($request)) {
            abort(403, 'Only Super Admin can disable, suspend, reject, delete, or reactivate protected management accounts.');
        }

        if (in_array($targetRole, ['super_admin', 'superadmin'], true) && $newStatus !== 'active') {
            $activeSuperAdmins = User::query()
                ->where('user_id', '!=', $target->user_id)
                ->where('account_status', 'active')
                ->whereHas('role', function ($query) {
                    $roleColumns = $this->roleNameColumns();

                    $query->where(function ($q) use ($roleColumns) {
                        foreach ($roleColumns as $column) {
                            $q->orWhereRaw(
                                "LOWER(REPLACE(REPLACE({$column}, ' ', '_'), '-', '_')) IN (?, ?)",
                                ['super_admin', 'superadmin']
                            );
                        }

                        if (empty($roleColumns)) {
                            $q->whereRaw('1 = 0');
                        }
                    });
                })
                ->count();

            abort_if($activeSuperAdmins < 1, 422, 'Cannot disable the last active Super Admin account.');
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
                if (
                    isset($role->{$field}) &&
                    $this->normalizeRoleName((string) $role->{$field}) === $normalized
                ) {
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

    private function normalizeRoleName(?string $role): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim((string) $role)));
    }

    private function normalizeStatus(?string $status): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim((string) $status)));
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        if ($email === '') {
            return null;
        }

        return strtolower($email);
    }

    private function normalizeMobileNumber(?string $mobile): string
    {
        $mobile = preg_replace('/[^\d+]/', '', trim((string) $mobile)) ?? '';

        if (str_starts_with($mobile, '+63')) {
            $mobile = '0' . substr($mobile, 3);
        }

        if (str_starts_with($mobile, '63') && strlen($mobile) === 12) {
            $mobile = '0' . substr($mobile, 2);
        }

        return $mobile;
    }

    private function isValidPhilippineMobile(string $mobile): bool
    {
        return preg_match('/^09\d{9}$/', $mobile) === 1;
    }

    private function isResidentRole(string $role): bool
    {
        return in_array($this->normalizeRoleName($role), ['resident', 'patient'], true);
    }

    /**
     * Normalize a sex/gender value to a value accepted by the users.sex DB
     * check constraint: sex IN ('male','female','other'). Returns null for
     * unknown/empty so the column simply stays unset instead of violating it.
     */
    private function normalizeSex(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return match (strtolower(trim($value))) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            'other', 'prefer not to say', 'prefer_not_to_say', 'prefer-not-to-say' => 'other',
            default => null,
        };
    }

    private function persistUserPatientFields(User $user, array $validated): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $columns = Schema::getColumnListing('users');
        $birthDate = $validated['birth_date'] ?? $validated['birthdate'] ?? null;
        // users.sex has a DB check constraint: sex IN ('male','female','other').
        // Normalize the Web Admin display label ("Male"/"Female") so it never
        // violates the constraint.
        $sex = $this->normalizeSex($validated['sex'] ?? $validated['gender'] ?? null);
        $fieldMap = [
            'birthday' => $birthDate,
            'birthdate' => $birthDate,
            'birth_date' => $birthDate,
            'date_of_birth' => $birthDate,
            'sex' => $sex,
            'gender' => $sex,
            'address' => $validated['address'] ?? null,
        ];

        $updates = [];

        foreach ($fieldMap as $field => $value) {
            if (!in_array($field, $columns, true)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $updates[$field] = $value;
        }

        if (empty($updates)) {
            return;
        }

        $user->forceFill($updates)->save();
    }

    private function persistResidentProfileFields(
        User $user,
        array $validated,
        string $firstName,
        string $lastName,
        string $mobile
    ): void {
        if (!Schema::hasTable('resident_profiles') || !Schema::hasColumn('resident_profiles', 'user_id')) {
            return;
        }

        $columns = Schema::getColumnListing('resident_profiles');
        $profileData = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'mobile_number' => $mobile,
            'phone_number' => $mobile,
            'contact_number' => $mobile,
            'sex' => $validated['sex'] ?? null,
            'gender' => $validated['sex'] ?? null,
            'birth_date' => $validated['birth_date'] ?? $validated['birthdate'] ?? null,
            'birthdate' => $validated['birthdate'] ?? $validated['birth_date'] ?? null,
            'date_of_birth' => $validated['birth_date'] ?? $validated['birthdate'] ?? null,
            'address' => $validated['address'] ?? null,
            'guardian_name' => $validated['guardian_name'] ?? null,
            'philhealth_number' => $validated['philhealth_number'] ?? null,
            'philhealth_no' => $validated['philhealth_number'] ?? null,
            'allergies' => $validated['allergies'] ?? null,
            'past_medical_history' => $validated['past_medical_history'] ?? null,
            'medical_history' => $validated['past_medical_history'] ?? null,
            'maintenance_medications' => $validated['maintenance_medications'] ?? null,
            'family_history' => $validated['family_history'] ?? null,
            'personal_social_history' => $validated['personal_social_history'] ?? null,
        ];

        $filtered = [];

        foreach ($profileData as $field => $value) {
            if (!in_array($field, $columns, true)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $filtered[$field] = $value;
        }

        if (empty($filtered)) {
            return;
        }

        ResidentProfile::query()->updateOrCreate(
            ['user_id' => $user->user_id],
            $filtered
        );
    }

    private function isStaffRole(string $role): bool
    {
        return in_array($this->normalizeRoleName($role), $this->staffRoles, true);
    }

    private function isProtectedRole(string $role): bool
    {
        return in_array($this->normalizeRoleName($role), $this->protectedRoles, true);
    }

    private function currentUserIsSuperAdmin(Request $request): bool
    {
        return (bool) $request->user()?->hasAnyRole(['super_admin', 'superadmin']);
    }

    private function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
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

    private function logUserDeleteHistory(Request $request, User $user, array $oldValues, string $reason): void
    {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        $actor = $request->user();

        $recordName = trim((string) $user->first_name . ' ' . (string) $user->last_name);

        if ($recordName === '') {
            $recordName = 'User #' . $user->user_id;
        }

        $metadata = [
            'reason' => $reason,
            'delete_reason' => $reason,
            'module' => 'users',

            'subject_type' => User::class,
            'subject_id' => $user->user_id,
            'subject_label' => $recordName,

            'record_type' => User::class,
            'record_id' => $user->user_id,
            'record_name' => $recordName,

            'restore_model' => User::class,
            'restore_key' => 'user_id',
            'restore_id' => $user->user_id,
        ];

        $payload = [
            'user_id' => $actor?->user_id,
            'user_role' => $actor?->role_name,

            'module' => 'users',
            'action' => 'user.deleted',
            'severity' => 'warning',

            'subject_type' => User::class,
            'subject_id' => $user->user_id,
            'subject_label' => $recordName,

            'old_values' => json_encode($oldValues),
            'new_values' => null,
            'metadata' => json_encode($metadata),

            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'device_type' => 'desktop',
            'http_method' => $request->method(),
            'route_name' => optional($request->route())->getName() ?? $request->path(),

            'created_at' => now(),
            'updated_at' => now(),
        ];

        $columns = Schema::getColumnListing('audit_logs');

        $safePayload = collect($payload)
            ->filter(fn ($value, $key) => in_array($key, $columns, true))
            ->toArray();

        if (!empty($safePayload)) {
            DB::table('audit_logs')->insert($safePayload);
        }
    }
}
