<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barangay extends Model
{
    protected $primaryKey = 'barangay_id';

    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'population',
    ];

    protected function casts(): array
    {
        return [
            'latitude'   => 'decimal:8',
            'longitude'  => 'decimal:8',
            'population' => 'integer',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'barangay_id', 'barangay_id');
    }

    public function residentProfiles(): HasMany
    {
        return $this->hasMany(ResidentProfile::class, 'barangay_id', 'barangay_id');
    }

    public function heatmaps(): HasMany
    {
        return $this->hasMany(BarangayHeatmap::class, 'barangay_id', 'barangay_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(HeatmapAlert::class, 'barangay_id', 'barangay_id');
    }

    // ── GIS Helpers ───────────────────────────────────────────────────────

    /**
     * Returns [latitude, longitude] as a simple array for GIS serialisation.
     */
    public function getCoordinatesAttribute(): array
    {
        return [
            'lat' => (float) ($this->latitude ?? 15.9196),
            'lng' => (float) ($this->longitude ?? 120.4123),
        ];
    }

    /**
     * Compute incidence rate per 1,000 residents for a given case count.
     * Used by HeatmapAnalyticsService for epidemiological normalisation.
     */
    public function incidenceRatePer1000(int $caseCount): float
    {
        if ($this->population <= 0) {
            return 0.0;
        }

        return round(($caseCount / $this->population) * 1000, 2);
    }
}