<?php
// app/Models/TelemedicineReferral.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelemedicineReferral extends Model
{
    protected $fillable = [
        'session_id',
        'issued_by',
        'resident_profile_id',
        'referral_type',
        'referred_to',
        'reason',
        'instructions',
        'follow_up_date',
        'is_urgent',
        'status',
    ];

    protected $casts = [
        'follow_up_date' => 'date',
        'is_urgent'      => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TelemedicineSession::class, 'session_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by', 'user_id');
    }

    public function residentProfile(): BelongsTo
    {
        return $this->belongsTo(ResidentProfile::class);
    }
}