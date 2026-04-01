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
        return $this->belongsTo(Barangay::class, 'rhu_id');
    }
}