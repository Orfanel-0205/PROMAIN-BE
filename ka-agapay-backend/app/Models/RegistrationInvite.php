<?php
// app/Models/RegistrationInvite.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationInvite extends Model
{
    protected $table = 'registration_invites';

    protected $fillable = [
        'token_hash',
        'intended_for',
        'mobile_number',
        'expires_at',
        'used_at',
        'used_by_user_id',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    // The raw token must never leave the generation response.
    protected $hidden = ['token_hash'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by_user_id', 'user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Status label for the admin invite list / evidence view. */
    public function statusLabel(): string
    {
        if ($this->isUsed()) {
            return 'used';
        }
        if ($this->isRevoked()) {
            return 'revoked';
        }
        if ($this->isExpired()) {
            return 'expired';
        }

        return 'active';
    }
}
