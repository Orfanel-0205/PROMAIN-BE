<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'account_status',
        'avatar',
        'profile_picture',
        'barangay',
        'birthday',
        'sex',
        'id_verified',
        'biometric_enabled',
        'biometric_token_hash',
        'failed_login_count',
        'locked_until',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'biometric_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'password'            => 'hashed',
            'birthday'            => 'date',        // ← Carbon; fixes toDateString() crash
            'last_login_at'       => 'datetime',
            'locked_until'        => 'datetime',    // ← needed by login() isFuture() check
            'id_verified'         => 'boolean',
            'biometric_enabled'   => 'boolean',
            'failed_login_count'  => 'integer',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function role(): BelongsTo
    {
        return $this->belongsTo(UserRole::class, 'role_id', 'role_id');
    }

    public function barangayRelation(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id', 'barangay_id');
    }

    // ── Accessors ─────────────────────────────────────────────────────────

    /**
     * Full URL for the profile picture stored on the public disk.
     * Falls back to null so formatUser() can safely use the legacy `avatar` field.
     */
    public function getProfilePictureUrlAttribute(): ?string
    {
        if (!$this->profile_picture) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')
            ->url($this->profile_picture);
    }
}