<?php
// app/Models/User.php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'role_id',
        'barangay_id',
        'first_name',
        'last_name',
        'email',
        'mobile_number',
        'password',
        'barangay',
        'birthday',
        'sex',
        'account_status',
        'id_verified',
        'biometric_enabled',
        'biometric_token_hash',
        'profile_picture',
        'avatar',
        'failed_login_count',
        'locked_until',
        'last_login_at',
        'last_login_ip',
        'staff_approved_by',
        'staff_approved_at',
        'rejection_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'biometric_token_hash',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'birthday' => 'date',
        'id_verified' => 'boolean',
        'biometric_enabled' => 'boolean',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
        'staff_approved_at' => 'datetime',
    ];

    protected $appends = [
        'full_name',
        'role_name',
        'capabilities',
    ];

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    public function getRoleNameAttribute(): string
    {
        return $this->getCurrentRoleName() ?? 'resident';
    }

    public function getCapabilitiesAttribute(): array
    {
        $role = $this->normalizeRoleName($this->role_name);

        $rolePermissions = $this->role?->permissions;

        if (is_array($rolePermissions) && !empty($rolePermissions)) {
            return array_values($rolePermissions);
        }

        return match ($role) {
            'super_admin', 'mho', 'municipal_mayor', 'it_staff' => [
                'full_access',
                'manage_users',
                'approve_staff',
                'manage_announcements',
                'manage_events',
                'manage_queue',
                'manage_consultations',
                'manage_prescriptions',
                'manage_inventory',
                'manage_sms',
                'view_analytics',
                'manage_settings',
            ],
            'rhu_admin', 'staff_admin' => [
                'manage_announcements',
                'manage_events',
                'manage_queue',
                'manage_consultations',
                'manage_prescriptions',
                'manage_inventory',
                'manage_sms',
                'view_analytics',
            ],
            'doctor' => [
                'manage_consultations',
                'manage_prescriptions',
                'view_patients',
            ],
            'nurse', 'midwife' => [
                'manage_queue',
                'manage_consultations',
                'manage_prescriptions',
                'manage_inventory',
                'view_patients',
            ],
            'bhw' => [
                'manage_queue',
                'view_patients',
            ],
            default => [],
        };
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(UserRole::class, 'role_id', 'role_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_approved_by', 'user_id');
    }

    public function hasRole(string|array $roles): bool
    {
        return $this->hasAnyRole($roles);
    }

    public function hasAnyRole(array|string $roles): bool
    {
        if (is_string($roles)) {
            $roles = [$roles];
        }

        $currentRole = $this->normalizeRoleName($this->getCurrentRoleName() ?? '');

        foreach ($roles as $role) {
            if ($currentRole === $this->normalizeRoleName((string) $role)) {
                return true;
            }
        }

        return false;
    }

    private function getCurrentRoleName(): ?string
    {
        $role = $this->relationLoaded('role')
            ? $this->role
            : $this->role()->first();

        if (!$role) {
            return null;
        }

        foreach (['name', 'role_name', 'slug', 'role', 'title', 'code'] as $field) {
            if (!empty($role->{$field})) {
                return (string) $role->{$field};
            }
        }

        return null;
    }

    private function normalizeRoleName(string $role): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($role)));
    }

    public function residentProfile(): HasOne
    {
        return $this->hasOne(ResidentProfile::class, 'user_id', 'user_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'user_id', 'user_id');
    }

    public function handledAppointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'handled_by', 'user_id');
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class, 'user_id', 'user_id');
    }

    public function attendedConsultations(): HasMany
    {
        return $this->hasMany(Consultation::class, 'attended_by', 'user_id');
    }
}