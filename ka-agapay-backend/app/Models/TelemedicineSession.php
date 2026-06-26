<?php
// app/Models/TelemedicineSession.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TelemedicineSession extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'request_id',
        'assigned_doctor_id',
        'bhw_companion_id',
        'scheduled_date',
        'scheduled_time',
        'estimated_duration_minutes',
        'session_mode',
        'session_link',
        'session_token',

        'room_id',
        'room_token',
        'ice_servers',

        'status',
        'started_at',
        'ended_at',
        'actual_duration_minutes',
        'consultation_id',
        'cancellation_reason',
        'cancelled_at',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'ice_servers' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TelemedicineRequest::class, 'request_id');
    }

    public function assignedDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_doctor_id', 'user_id');
    }

    public function bhwCompanion(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bhw_companion_id', 'user_id');
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function notes(): HasOne
    {
        return $this->hasOne(TelemedicineSessionNote::class, 'session_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(TelemedicineReferral::class, 'session_id');
    }

    public function logs(): MorphMany
    {
        return $this->morphMany(TelemedicineLog::class, 'loggable');
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = [
            'scheduled' => ['waiting', 'active', 'no_show', 'cancelled'],
            'waiting' => ['active', 'no_show', 'cancelled'],
            'active' => ['paused', 'ended'],
            'paused' => ['active', 'ended'],
            'ended' => [],
            'no_show' => [],
            'cancelled' => [],
        ];

        return in_array($newStatus, $allowed[$this->status] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['ended', 'no_show', 'cancelled'], true);
    }
}