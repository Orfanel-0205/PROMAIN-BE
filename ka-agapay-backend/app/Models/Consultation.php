<?php
// app/Models/Consultation.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Consultation extends Model
{
    protected $fillable = [
        'appointment_id',
        'user_id',
        'attended_by',
        'consultation_date',
        'chief_complaint',
        'diagnosis',
        'treatment',
        'status',
        'subjective',
        'objective',
        'assessment',
        'plan',
        'notes',
        'started_at',
        'completed_at',

        // Slice B1 — visit tracking (additive)
        'first_attended_at',
        'first_attended_by',
        'draft_saved_at',
        'itr_snapshot',

        // Diagnosis + ITR heatmap freshness (additive)
        'heatmap_posted_at',
        'heatmap_signal_expires_at',

        // Vitals / RHU staff-filled clinical fields (additive)
        'vital_signs',
        'weight',
        'bmi',
        'temperature_celsius',
        'blood_pressure',
        'spo2',
        'heart_rate',
        'visual_acuity',
        'visual_acuity_left',
        'visual_acuity_right',

        // Pediatric client measurements
        'pediatric_client',
        'length_cm',
        'head_circumference_cm',
        'skinfold_thickness_cm',
        'waist_cm',
        'hip_cm',
        'limbs_cm',
        'muac_cm',

        // General survey
        'general_survey',
        'awake_and_alert',
        'altered_sensorium',

        // Prescription summary (free text)
        'prescribed_drugs',
    ];

    protected $casts = [
        'consultation_date' => 'date:Y-m-d',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',

        'first_attended_at' => 'datetime',
        'draft_saved_at' => 'datetime',
        'itr_snapshot' => 'array',

        'heatmap_posted_at' => 'datetime',
        'heatmap_signal_expires_at' => 'datetime',

        'pediatric_client' => 'boolean',
        'awake_and_alert' => 'boolean',
        'altered_sensorium' => 'boolean',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function attendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attended_by', 'user_id');
    }

    public function firstAttendant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_attended_by', 'user_id');
    }

    public function medicalReports(): HasMany
    {
        return $this->hasMany(MedicalReport::class, 'consultation_id');
    }

    public function queueTicket(): HasOne
    {
        return $this->hasOne(QueueTicket::class, 'consultation_id');
    }
}
