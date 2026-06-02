<?php
// app/Models/HeatmapAlert.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records epidemiological alerts triggered when disease
 * incidence in a barangay exceeds the historical baseline
 * threshold, or when queue congestion reaches critical levels.
 *
 * Provides a full audit trail including who resolved
 * the alert and when, supporting RHU accountability workflows.
 */
class HeatmapAlert extends Model
{
    protected $fillable = [
        'barangay_id',
        'disease_type',
        'alert_type',
        'severity',
        'trigger_message',
        'case_count',
        'baseline_average',
        'deviation_factor',
        'is_resolved',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'case_count'       => 'integer',
            'baseline_average' => 'decimal:2',
            'deviation_factor' => 'decimal:2',
            'is_resolved'      => 'boolean',
            'resolved_at'      => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id', 'barangay_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by', 'user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeForDisease($query, string $disease)
    {
        return $query->where('disease_type', $disease);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('alert_type', $type);
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function resolve(int $userId, ?string $notes = null): void
    {
        $this->update([
            'is_resolved'     => true,
            'resolved_by'     => $userId,
            'resolved_at'     => now(),
            'resolution_notes'=> $notes,
        ]);
    }
}
