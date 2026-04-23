<?php
// app/Models/Prescription.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prescription extends Model
{
    use SoftDeletes;

    // ── Status constants ──────────────────────────────────────────────────────
    public const STATUS_ACTIVE               = 'active';
    public const STATUS_DISPENSED            = 'dispensed';
    public const STATUS_PARTIALLY_DISPENSED  = 'partially_dispensed';
    public const STATUS_EXPIRED              = 'expired';
    public const STATUS_CANCELLED            = 'cancelled';
    public const STATUS_VOIDED               = 'voided';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_DISPENSED,
        self::STATUS_PARTIALLY_DISPENSED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
        self::STATUS_VOIDED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_VOIDED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'resident_profile_id',
        'prescribed_by',
        'consultation_id',
        'telemedicine_session_id',
        'prescription_number',
        'rhu_id',
        'prescription_date',
        'valid_until',
        'diagnosis',
        'diagnosis_code',
        'medications',
        'has_controlled_substances',
        's2_license_number',
        'additional_instructions',
        'dispensing_notes',
        'status',
        'dispensed_at',
        'dispensed_by',
        'voided_at',
        'voided_by',
        'void_reason',
        'file_path',
    ];

    protected $casts = [
        'medications'               => 'array',
        'prescription_date'         => 'date',
        'valid_until'               => 'date',
        'dispensed_at'              => 'datetime',
        'voided_at'                 => 'datetime',
        'has_controlled_substances' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function residentProfile(): BelongsTo
    {
        return $this->belongsTo(ResidentProfile::class);
    }

    public function prescribedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescribed_by', 'user_id');
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function telemedicineSession(): BelongsTo
    {
        return $this->belongsTo(TelemedicineSession::class, 'telemedicine_session_id');
    }

    public function dispensedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by', 'user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by', 'user_id');
    }

    public function dispensingLogs(): HasMany
    {
        return $this->hasMany(PrescriptionDispensingLog::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForResident($query, int $profileId)
    {
        return $query->where('resident_profile_id', $profileId);
    }

    public function scopeForRhu($query, int $rhuId)
    {
        return $query->where('rhu_id', $rhuId);
    }

    public function scopeControlled($query)
    {
        return $query->where('has_controlled_substances', true);
    }

    // ── Business Logic Helpers ────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES);
    }

    public function isDispensable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && !$this->isExpired();
    }

    public function getAuditLabel(): string
    {
        return "Prescription #{$this->prescription_number}";
    }
}
