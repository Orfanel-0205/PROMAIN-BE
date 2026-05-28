<?php
//app/models/User.php
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
        'barangay_id',        // FK to barangays table (nullable)
        'first_name',
        'last_name',
        'email',
        'mobile_number',
        'password',
        'account_status',
        'avatar',             // legacy field
        'profile_picture',    // NEW: stored path in public disk
        'barangay',           // NEW: plain string from fixed barangay list
        'birthday',           // NEW: date
        'sex',                // NEW: enum
        'id_verified',        // NEW: bool flag
        'biometric_enabled',  // NEW: bool flag
        'biometric_token_hash', // NEW: hashed biometric token
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
            'password'          => 'hashed',
            'birthday'          => 'date',
            'last_login_at'     => 'datetime',
            'id_verified'       => 'boolean',
            'biometric_enabled' => 'boolean',
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

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Full URL for the profile picture.
     * Returns null if no picture is set.
     */
    public function getProfilePictureUrlAttribute(): ?string
    {
        if (!$this->profile_picture) {
            return null;
        }
        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->profile_picture);
    }
}