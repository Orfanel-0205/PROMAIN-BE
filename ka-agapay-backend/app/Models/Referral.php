<?php
// app/Models/Referral.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Referral extends Model
{
    use SoftDeletes;

    public const STATUSES = ['pending', 'acknowledged', 'in_progress', 'completed', 'cancelled'];
    public const TYPES    = ['follow_up', 'specialist', 'hospital', 'laboratory', 'bhw_monitoring', 'pharmacy'];
    public const URGENCY  = ['routine', 'urgent', 'emergency'];

    protected $fillable = [
        'referable_type', 'referable_id',
        'resident_profile_id', 'issued_by', 'acknowledged_by', 'assigned_bhw_id',
        'referral_type', 'referred_facility', 'referred_department', 'referred_physician',
        'reason', 'clinical_summary', 'instructions', 'urgency',
        'follow_up_date', 'follow_up_time', 'status',
        'outcome_notes', 'acknowledged_at', 'completed_at', 'cancelled_at',
        'cancellation_reason', 'requires_bhw_monitoring', 'is_urgent',
    ];

    protected $casts = [
        'follow_up_date'          => 'date',
        'acknowledged_at'         => 'datetime',
        'completed_at'            => 'datetime',
        'cancelled_at'            => 'datetime',
        'requires_bhw_monitoring' => 'boolean',
        'is_urgent'               => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function referable(): MorphTo
    {
        return $this->morphTo();
    }

    public function residentProfile(): BelongsTo
    {
        return $this->belongsTo(ResidentProfile::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by', 'user_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by', 'user_id');
    }

    public function assignedBhw(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_bhw_id', 'user_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ReferralUpdate::class)->orderBy('created_at');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeUrgent($query)
    {
        return $query->where('is_urgent', true);
    }

    public function scopeForBhw($query, int $bhwId)
    {
        return $query->where('assigned_bhw_id', $bhwId);
    }

    public function scopeDueToday($query)
    {
        return $query->whereDate('follow_up_date', today());
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', ['completed', 'cancelled']);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = [
            'pending'      => ['acknowledged', 'cancelled'],
            'acknowledged' => ['in_progress', 'cancelled'],
            'in_progress'  => ['completed', 'cancelled'],
            'completed'    => [],
            'cancelled'    => [],
        ];

        return in_array($newStatus, $allowed[$this->status] ?? []);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'cancelled']);
    }

    public function getAuditLabel(): string
    {
        return "Referral #{$this->id} ({$this->referral_type})";
    }
}
