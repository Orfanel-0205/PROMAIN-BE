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
        'form_type',
        'prescription_number',
        'rhu_id',
        'prescription_date',
        'valid_until',
        'diagnosis',
        'diagnosis_code',
        'clinical_impression',
        'request_reason',
        'priority',
        'request_notes',
        'lab_tests',
        'medications',
        'has_controlled_substances',
        's2_license_number',
        'additional_instructions',
        'dispensing_notes',
        'status',
        'dispensed_at',
        'dispensed_by',
        'released_at',
        'released_by',
        'voided_at',
        'voided_by',
        'void_reason',
        'file_path',
    ];

    protected $casts = [
        'medications'               => 'array',
        'lab_tests'                 => 'array',
        'prescription_date'         => 'date',
        'valid_until'               => 'date',
        'dispensed_at'              => 'datetime',
        'released_at'               => 'datetime',
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

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by', 'user_id');
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

    /** Active, or partly handed over with the rest still to come, and not expired. */
    public function isDispensable(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_PARTIALLY_DISPENSED], true)
            && !$this->isExpired();
    }

    /** Prescribed quantity of one medication entry; the same reading the stock deduction uses. */
    public static function prescribedQuantity(array $medicine): int
    {
        return max(1, (int) ($medicine['dispense_quantity'] ?? $medicine['quantity'] ?? $medicine['qty'] ?? 1));
    }

    /**
     * Quantity still to be handed over, per medication (list index => quantity).
     * `dispensed_quantity` on each entry is the running total already given.
     * Entries without a name cannot be matched to stock and count as nothing.
     *
     * @return array<int, int>
     */
    public function remainingQuantities(): array
    {
        return array_map(
            fn ($medicine) => is_array($medicine) && trim((string) ($medicine['name'] ?? '')) !== ''
                ? max(0, self::prescribedQuantity($medicine) - (int) ($medicine['dispensed_quantity'] ?? 0))
                : 0,
            array_values($this->medications ?? [])
        );
    }

    public function getAuditLabel(): string
    {
        return "Prescription #{$this->prescription_number}";
    }
}
