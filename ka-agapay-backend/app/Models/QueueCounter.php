<?php
// app/Models/QueueCounter.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueCounter extends Model
{
    protected $fillable = [
        'rhu_id',
        'service_type',
        'queue_date',
        'last_issued_number',
        'current_serving_number',
        'is_active',
    ];

    protected $casts = [
        'queue_date' => 'date',
        'is_active'  => 'boolean',
    ];

    public function rhu(): BelongsTo
    {
        // rhu_id is a facility (rhus.id). This pointed at BARANGAYS, from
        // before facilities had a table, so RHU 1 was named "Abonagan"
        // (barangay #1) on queue screens and notifications (Oct 2026).
        return $this->belongsTo(RhuFacility::class, 'rhu_id', 'id');
    }
}