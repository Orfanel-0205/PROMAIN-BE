<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiRequest extends Model
{ 
    public $timestamps = false;

    public const TYPE_TRIAGE_SCORE = 'triage_score';
    public const TYPE_SYMPTOM_ANALYSIS = 'symptom_analysis';
    public const TYPE_DEMAND_FORECAST = 'demand_forecast';
    public const TYPE_SOAP_DRAFT = 'soap_draft';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'triggered_by', 'request_type', 'model_used',
        'input_payload', 'output_payload', 'processing_time_ms',
        'status', 'error_message', 'subject_type', 'subject_id',
        'was_applied', 'created_at', 'completed_at',
        
    ];

    protected $casts = [
        'input_payload' => 'array',
        'output_payload' => 'array',
        'was_applied' => 'boolean',
        'processing_time_ms' => 'integer',
    ];

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by', 'user_id');
    }

    public function triageScore(): HasOne
    {
        return $this->hasOne(AiTriageScore::class, 'ai_request_id');
    }

}
