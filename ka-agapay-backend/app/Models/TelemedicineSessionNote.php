<?php
// app/Models/TelemedicineSessionNote.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelemedicineSessionNote extends Model
{
    protected $fillable = [
        'session_id',
        'recorded_by',
        'subjective',
        'objective',
        'assessment',
        'plan',
        'primary_diagnosis_code',
        'primary_diagnosis_label',
        'medications',
        // Missing here, so even a request that carried a transcript and
        // a service that assigned one would have been discarded without
        // a word by updateOrCreate. The column, the request rule and the
        // write all have to agree before a single character is stored.
        'transcript',
        'is_finalized',
        'finalized_at',
    ];

    protected $casts = [
        'medications'   => 'array',
        'is_finalized'  => 'boolean',
        'finalized_at'  => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TelemedicineSession::class, 'session_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by', 'user_id');
    }
}