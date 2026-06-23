<?php
// app/Models/FollowUpReminder.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUpReminder extends Model
{
    protected $table = 'follow_up_reminders';

    public const URGENCY_LEVELS = ['routine', 'watch', 'urgent'];
    public const STATUSES = ['pending', 'scheduled', 'completed', 'missed', 'cancelled'];

    protected $fillable = [
        'consultation_id',
        'appointment_id',
        'user_id',
        'resident_profile_id',
        'rhu_id',
        'created_by',
        'patient_name',
        'mobile_number',
        'follow_up_at',
        'follow_up_date',
        'follow_up_time',
        'reason',
        'instructions',
        'urgency',
        'status',
        'sms_enabled',
        'sms_status',
        'sms_sent_at',
        'sms_error',
    ];

    protected $casts = [
        'follow_up_at' => 'datetime',
        'follow_up_date' => 'date:Y-m-d',
        'sms_enabled' => 'boolean',
        'sms_sent_at' => 'datetime',
    ];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'consultation_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function rhu(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'rhu_id', 'barangay_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
