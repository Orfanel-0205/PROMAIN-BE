<?php
// app/Models/TelemedicineRequest.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TelemedicineRequest extends Model
{
    use SoftDeletes;

    protected $table = 'telemedicine_requests';

    protected $fillable = [
        'resident_profile_id',
        'requested_by',
        'queue_ticket_id',
        'appointment_id',
        'rhu_id',
        'endorsed_by_bhw',
        'is_bhw_assisted',
        'bhw_notes',
        'chief_complaint',
        'urgency_level',
        'symptoms',
        'additional_notes',
        'screened_by',
        'screening_notes',
        'screened_at',
        'vital_temperature',
        'vital_bp',
        'vital_heart_rate',
        'vital_respiratory_rate',
        'endorsed_to',
        'endorsed_at',
        'status',
        'rejection_reason',
        'cancellation_reason',
        'cancelled_at',

        'completed_at',
        'board_visible_until',
        'archived_at',
        'archive_reason',
    ];

    protected $casts = [
        'symptoms'           => 'array',
        'is_bhw_assisted'    => 'boolean',
        'screened_at'        => 'datetime',
        'endorsed_at'        => 'datetime',
        'cancelled_at'       => 'datetime',
        'completed_at'       => 'datetime',
        'board_visible_until' => 'datetime',
        'archived_at'        => 'datetime',
    ];

    public function residentProfile(): BelongsTo
    {
        return $this->belongsTo(ResidentProfile::class, 'resident_profile_id', 'id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by', 'user_id');
    }

    public function endorsedByBhw(): BelongsTo
    {
        return $this->belongsTo(User::class, 'endorsed_by_bhw', 'user_id');
    }

    public function screenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'screened_by', 'user_id');
    }

    public function endorsedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'endorsed_to', 'user_id');
    }

    public function rhu(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'rhu_id', 'barangay_id');
    }

    public function queueTicket(): BelongsTo
    {
        return $this->belongsTo(QueueTicket::class, 'queue_ticket_id', 'id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id', 'id');
    }

    public function session(): HasOne
    {
        return $this->hasOne(TelemedicineSession::class, 'request_id', 'id');
    }

    public function logs(): MorphMany
    {
        return $this->morphMany(TelemedicineLog::class, 'loggable');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForRhu($query, int $rhuId)
    {
        return $query->where('rhu_id', $rhuId);
    }

    public function scopeUrgent($query)
    {
        return $query->whereIn('urgency_level', ['urgent', 'emergency']);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['rejected', 'cancelled', 'completed'], true);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = [
            'pending'            => ['screening', 'screened', 'rejected', 'cancelled'],
            'screening'          => ['screened', 'cancelled'],
            'screened'           => ['endorsed_to_doctor', 'scheduled', 'rejected', 'cancelled'],
            'endorsed_to_doctor' => ['scheduled', 'cancelled'],
            'scheduled'          => ['completed', 'cancelled'],
            'rejected'           => [],
            'cancelled'          => [],
            'completed'          => [],
        ];

        return in_array($newStatus, $allowed[$this->status] ?? [], true);
    }
}
