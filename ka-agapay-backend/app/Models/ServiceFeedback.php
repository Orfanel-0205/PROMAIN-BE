<?php
// app/Models/ServiceFeedback.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceFeedback extends Model
{
    protected $table = 'service_feedback';

    public const SERVICE_TYPES = [
        // Medical follow-up (current default for new submissions)
        'health_followup',

        // Legacy service types kept for backward compatibility
        'onsite_consultation',
        'online_consultation',
        'queue_service',
        'laboratory',
        'prescription',
        'general_rhu_service',
    ];

    public const STATUSES = [
        'submitted',
        'reviewed',
        'responded',
        'archived',
    ];

    public const CONDITION_STATUSES = [
        'improved',
        'same',
        'worse',
        'recovered',
    ];

    public const MEDICATION_TAKEN = [
        'yes',
        'no',
        'not_prescribed',
        'not_applicable',
    ];

    public const URGENCY_LEVELS = [
        'routine',
        'watch',
        'urgent',
    ];

    protected $fillable = [
        'user_id',
        'rhu_id',
        'appointment_id',
        'consultation_id',
        'queue_ticket_id',
        'prescription_id',
        'laboratory_result_id',
        'service_type',
        'rating',
        'comment',
        'admin_response',
        'responded_by',
        'responded_at',
        'status',

        // Health follow-up (medical) fields
        'followup_type',
        'condition_status',
        'symptoms_present',
        'medication_taken',
        'side_effects',
        'side_effects_description',
        'patient_message',
        'needs_follow_up',
        'urgency_level',
        'reviewed_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'responded_at' => 'datetime',

        'symptoms_present' => 'boolean',
        'side_effects' => 'boolean',
        'needs_follow_up' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function rhu(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'rhu_id', 'barangay_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'consultation_id');
    }

    public function queueTicket(): BelongsTo
    {
        return $this->belongsTo(QueueTicket::class, 'queue_ticket_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by', 'user_id');
    }
}
