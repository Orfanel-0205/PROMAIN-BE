<?php

namespace App\Models;

use App\Support\Rhu;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;
    use SoftDeletes;

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'role_id',
        'barangay_id',
        'assigned_rhu_id',

        'first_name',
        'middle_name',
        'last_name',
        'email',
        'mobile_number',
        'password',

        'account_status',
        'id_verified',
        'staff_approved_by',
        'staff_approved_at',
        'rejection_reason',

        'terms_accepted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',

        'email_verified_at',
        'otp_code',
        'otp_expires_at',
        'last_login_at',
        'last_login_ip',
        'failed_login_count',
        'locked_until',

        'barangay',
        'birthday',
        'sex',

        'profile_picture',
        'avatar',

        'deleted_by',
        'delete_reason',

        'biometric_enabled',
        'biometric_token_hash',

        // One-time "Getting Started" tour flag (web admin first login).
        'has_seen_onboarding',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'otp_code',
        'biometric_token_hash',
        // Derived from the role; see booted().
        'is_staff',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'otp_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
        // Team Chat presence heartbeat — must be a Carbon instance for the
        // "active within N minutes" comparison.
        'last_active_at' => 'datetime',
        'locked_until' => 'datetime',
        // Set by UserMobileObserver; pauses viewing API keys for a day after a change.
        'mobile_changed_at' => 'datetime',
        // Set by "Forgot password"; also pauses viewing API keys for a day.
        'password_reset_at' => 'datetime',
        'has_seen_onboarding' => 'boolean',
        'staff_approved_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'birthday' => 'date',
        'id_verified' => 'boolean',
        'biometric_enabled' => 'boolean',
        'deleted_at' => 'datetime',
        'is_staff' => 'boolean',
    ];

    protected $appends = [
        'full_name',
        'role_name',
        'capabilities',
    ];

    /** The roles that are not staff. Every other role signs in to the admin website. */
    public const RESIDENT_ROLES = ['resident', 'patient'];

    /**
     * Whether this is a staff account: any role but a resident's. The rule
     * that sets is_staff, read from the role itself so it also holds for a
     * row saved before that column existed. An account with no role is not
     * staff.
     */
    public function isStaffAccount(): bool
    {
        $role = strtolower(trim((string) $this->role?->name));

        return $role !== '' && !in_array($role, self::RESIDENT_ROLES, true);
    }

    private static ?bool $hasStaffColumn = null;

    /*
     * ONE NUMBER, ONE STAFF ACCOUNT AND ONE RESIDENT ACCOUNT
     *
     * RHU staff are residents too, so a person may have a staff account for
     * the admin website and a resident account for the mobile app on the same
     * phone. is_staff records which kind each account is and is set from the
     * role on every save, so a role change moves the account to the other
     * kind. A number may be on one active account of each kind; the database
     * index users_mobile_number_kind_unique holds that, and this check turns
     * a collision into an ordinary 422 from whichever screen caused it --
     * creating a user, editing a profile, changing a role, restoring an
     * archived account -- instead of a database error.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (!self::hasStaffColumn()) {
                return;
            }

            if (!$user->exists || $user->isDirty('role_id')) {
                $user->is_staff = self::roleIsStaff($user->role_id);
            }

            if (!$user->isDirty(['mobile_number', 'is_staff']) || trim((string) $user->mobile_number) === '') {
                return;
            }

            $taken = static::query()
                ->where('mobile_number', $user->mobile_number)
                ->where('is_staff', (bool) $user->is_staff)
                ->when($user->exists, fn ($query) => $query->where('user_id', '!=', $user->user_id))
                ->exists();

            if ($taken) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'mobile_number' => [$user->is_staff
                        ? 'This mobile number is already on another staff account.'
                        : 'This mobile number is already on another resident account.'],
                ]);
            }
        });
    }

    /** Whether a role makes an account staff: anything but resident or patient. */
    public static function roleIsStaff(mixed $roleId): bool
    {
        if ($roleId === null || $roleId === '') {
            return false;
        }

        $name = strtolower(trim((string) UserRole::query()->where('role_id', $roleId)->value('name')));

        return $name !== '' && !in_array($name, self::RESIDENT_ROLES, true);
    }

    /**
     * Only accounts of one kind: staff for the admin website, residents for
     * the app. A no-op until the is_staff column exists, so code deployed a
     * moment before its migration still finds accounts.
     */
    public function scopeOfKind($query, bool $staff)
    {
        return self::hasStaffColumn() ? $query->where('users.is_staff', $staff) : $query;
    }

    /**
     * Accounts of one kind first. For sign-in, where a person with both kinds
     * of account must reach the right one, but someone with only the other
     * kind should still get that screen's own answer ("this portal is for RHU
     * staff only") rather than "wrong password".
     */
    public function scopePreferKind($query, bool $staff)
    {
        return self::hasStaffColumn() ? $query->orderBy('users.is_staff', $staff ? 'desc' : 'asc') : $query;
    }

    /**
     * The validation rule for a mobile number on an account of this kind:
     * unique among active accounts of the same kind only.
     */
    public static function uniqueMobileRule(bool $staff, mixed $ignoreUserId = null): \Illuminate\Validation\Rules\Unique
    {
        $rule = \Illuminate\Validation\Rule::unique('users', 'mobile_number')
            ->whereNull('deleted_at')
            ->where(fn ($query) => self::hasStaffColumn() ? $query->where('is_staff', $staff) : $query);

        return $ignoreUserId === null ? $rule : $rule->ignore($ignoreUserId, 'user_id');
    }

    public static function hasStaffColumn(): bool
    {
        return self::$hasStaffColumn ??= \Illuminate\Support\Facades\Schema::hasColumn('users', 'is_staff');
    }

    public function role()
    {
        return $this->belongsTo(UserRole::class, 'role_id', 'role_id');
    }

    public function residentProfile()
    {
        return $this->hasOne(ResidentProfile::class, 'user_id', 'user_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(collect([
            $this->first_name,
            $this->last_name,
        ])->filter()->join(' '));
    }

    public function getRoleNameAttribute(): ?string
    {
        return $this->role?->name;
    }

    public function getCapabilitiesAttribute(): array
    {
        $permissions = $this->role?->permissions ?? [];

        if (is_string($permissions)) {
            $decoded = json_decode($permissions, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($permissions) ? $permissions : [];
    }

    public function hasRole(string $role): bool
    {
        return strtolower((string) $this->role?->name) === strtolower($role);
    }

    public function hasAnyRole(array $roles): bool
    {
        $current = strtolower((string) $this->role?->name);

        return in_array($current, array_map('strtolower', $roles), true);
    }

    /**
     * Roles that may view/manage ALL RHUs and filter by RHU.
     * Everyone else is locked to a single RHU.
     */
    public function isGlobalRhuScope(): bool
    {
        return $this->hasAnyRole(['super_admin', 'mho']);
    }

    /**
     * The RHU this staff/admin account is bound to.
     * Prefers the explicit assigned_rhu_id, falling back to barangay_id so
     * existing accounts keep working before assignments are set.
     */
    public function effectiveRhuId(): ?int
    {
        return Rhu::resolveRhuIdFromUser($this);
    }
}
