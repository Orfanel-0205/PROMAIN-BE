<?php
// app/Models/DiseaseCluster.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Represents a detected spatial cluster of disease cases
 * across one or more adjacent barangays.
 *
 * Used by HeatmapAlertService for outbreak detection
 * and displayed on the GIS analytics dashboard.
 */
class DiseaseCluster extends Model
{
    protected $fillable = [
        'disease_type',
        'case_count',
        'barangay_count',
        'center_latitude',
        'center_longitude',
        'radius_km',
        'density_index',
        'affected_barangays',
        'period_start',
        'period_end',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'case_count'          => 'integer',
            'barangay_count'      => 'integer',
            'radius_km'           => 'decimal:2',
            'density_index'       => 'decimal:2',
            'affected_barangays'  => 'array',
            'period_start'        => 'date',
            'period_end'          => 'date',
            'detected_at'         => 'datetime',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeForDisease($query, string $disease)
    {
        return $query->where('disease_type', $disease);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('detected_at', '>=', now()->subDays($days));
    }
}
