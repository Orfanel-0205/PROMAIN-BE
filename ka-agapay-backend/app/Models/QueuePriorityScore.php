<?php
//app/models/QueuePriorityScore.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted audit record of every priority score computation.
 *
 * Each time QueuePrioritizationService::computePriorityScore() runs,
 * the result is saved here, providing a fully auditable history of
 * why each patient received their queue position.
 *
 * This satisfies the thesis requirement for explainable, healthcare-safe,
 * auditable queue scoring (FURPS: Reliability + Supportability).
 */
class QueuePriorityScore extends Model
{
    protected $fillable = [
        'queue_ticket_id',
        'resident_profile_id',
        'priority_score',
        'priority_category',
        'queue_type',
        'breakdown',
        'contributing_factors',
        'ai_severity_score',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority_score'       => 'integer',
            'ai_severity_score'    => 'integer',
            'breakdown'            => 'array',
            'contributing_factors' => 'array',
            'computed_at'          => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function queueTicket(): BelongsTo
    {
        return $this->belongsTo(QueueTicket::class, 'queue_ticket_id');
    }

    public function residentProfile(): BelongsTo
    {
        return $this->belongsTo(ResidentProfile::class, 'resident_profile_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeForToday($query)
    {
        return $query->whereDate('computed_at', today());
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('priority_category', $category);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Human-readable summary of which factors drove the score.
     * Used in staff-facing queue displays.
     */
    public function getScoreExplanationAttribute(): string
    {
        $factors = $this->contributing_factors ?? [];
        return empty($factors)
            ? 'Standard priority'
            : 'Priority factors: ' . implode(', ', array_map(
                fn($f) => ucwords(str_replace('_', ' ', $f)),
                $factors
            ));
    }
}