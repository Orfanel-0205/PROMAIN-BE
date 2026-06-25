<?php

namespace App\Models;

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
        'last_name',
        'email',
        'mobile_number',
        'password',

        'account_status',
        'id_verified',
        'staff_approved_by',
        'staff_approved_at',
        'rejection_reason',

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
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'otp_code',
        'biometric_token_hash',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'otp_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
        'locked_until' => 'datetime',
        'staff_approved_at' => 'datetime',
        'birthday' => 'date',
        'id_verified' => 'boolean',
        'biometric_enabled' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'full_name',
        'role_name',
        'capabilities',
    ];

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
        $rhu = $this->assigned_rhu_id ?? $this->barangay_id ?? null;

        return $rhu ? (int) $rhu : null;
    }
}
