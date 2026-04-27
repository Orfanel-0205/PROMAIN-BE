<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTriageScore extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'ai_request_id', 'telemedicine_request_id', 'queue_ticket_id',
        'ai_score', 'recommended_urgency', 'contributing_factors',
        'confidence', 'doctor_overrode', 'override_reason',
    ];

    protected $casts = [
         'contributing_factors' => 'array',
        'confidence'           => 'decimal:4',
        'doctor_overrode'      => 'boolean',
        'created_at'           => 'datetime',
    ];
    public function aiRequest(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'ai_request_id');
    }

    public function telemedicineRequest(): BelongsTo
    {
        return $this->belongsTo(TelemedicineRequest::class);
    }

    public function queueTicket(): BelongsTo
    {
        return $this->belongsTo(QueueTicket::class);
    }

}