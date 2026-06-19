<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminRegistrationController extends Controller
{
    private array $staffRoles = [
        'doctor',
        'nurse',
        'midwife',
        'bhw',
        'staff',
        'staff_admin',
        'rhu_admin',
    ];

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'mobile_number' => $this->normalizeMobile($request->input('mobile_number')),
            'role' => $this->normalizeRole($request->input('role', 'staff')),
        ]);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'mobile_number' => ['required', 'regex:/^09\d{9}$/', 'unique:users,mobile_number'],
            'barangay' => ['nullable', 'string', 'max:150'],
            'role' => ['required', Rule::in($this->staffRoles)],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => ['required'],
        ]);

        $role = UserRole::where('name', $validated['role'])->first();

        if (!$role) {
            return response()->json([
                'message' => "Role '{$validated['role']}' does not exist. Run UserRoleSeeder first.",
            ], 422);
        }

        $user = DB::transaction(function () use ($validated, $role) {
            $user = new User();
            $user->role_id = $role->role_id;
            $user->first_name = trim($validated['first_name']);
            $user->last_name = trim($validated['last_name']);
            $user->email = $validated['email'] ?? null;
            $user->mobile_number = $validated['mobile_number'];
            $user->password = Hash::make($validated['password']);
            $user->account_status = 'pending';

            if (Schema::hasColumn('users', 'barangay')) {
                $user->barangay = $validated['barangay'] ?? null;
            }

            if (Schema::hasColumn('users', 'id_verified')) {
                $user->id_verified = false;
            }

            if (Schema::hasColumn('users', 'staff_approved_by')) {
                $user->staff_approved_by = null;
            }

            if (Schema::hasColumn('users', 'staff_approved_at')) {
                $user->staff_approved_at = null;
            }

            if (Schema::hasColumn('users', 'rejection_reason')) {
                $user->rejection_reason = null;
            }

            $user->save();

            return $user->fresh()->load('role');
        });

        return response()->json([
            'message' => 'Registration submitted. Please wait for MHO, IT Staff, or Super Admin approval.',
            'data' => [
                'user_id' => $user->user_id,
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'mobile_number' => $user->mobile_number,
                'email' => $user->email,
                'role' => $user->role?->name,
                'account_status' => $user->account_status,
            ],
        ], 201);
    }

    private function normalizeMobile(mixed $value): string
    {
        $mobile = preg_replace('/[^\d+]/', '', (string) $value);

        if (str_starts_with($mobile, '+63')) {
            return '0' . substr($mobile, 3);
        }

        if (str_starts_with($mobile, '63') && strlen($mobile) === 12) {
            return '0' . substr($mobile, 2);
        }

        return $mobile;
    }

    private function normalizeRole(mixed $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim((string) $value)));
    }
}