<?php
// app/Models/BarangayHeatmap.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores daily heatmap aggregation data per barangay per disease type.
 *
 * Each row represents one disease in one barangay on one day,
 * holding the computed heatmap intensity, risk level, queue density,
 * and GIS coordinates for direct map rendering.
 */
class BarangayHeatmap extends Model
{
    protected $fillable = [
        'barangay_id',
        'disease_type',
        'active_cases',
        'queue_density',
        'latitude',
        'longitude',
        'heatmap_intensity',
        'risk_level',
        'top_case_type',
        'log_date',
    ];

    protected function casts(): array
    {
        return [
            'active_cases'      => 'integer',
            'queue_density'     => 'integer',
            'heatmap_intensity' => 'decimal:2',
            'log_date'          => 'date',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id', 'barangay_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeForDate($query, string $date)
    {
        return $query->where('log_date', $date);
    }

    public function scopeForToday($query)
    {
        return $query->where('log_date', today());
    }

    public function scopeForDisease($query, string $disease)
    {
        return $query->where('disease_type', $disease);
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', ['high', 'critical']);
    }

    public function scopeCritical($query)
    {
        return $query->where('risk_level', 'critical');
    }
}
