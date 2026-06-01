<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    protected $fillable = [
        'user_id',
        'handled_by',
        'appointment_date',
        'appointment_time',
        'purpose',
        'status',
        'notes',

        'consultation_type',
        'reason',
        'symptoms',

        'rejection_reason',
        'approved_at',
        'scheduled_at',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'approved_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by', 'user_id');
    }
}