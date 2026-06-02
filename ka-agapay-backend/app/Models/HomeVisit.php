<?php
// app/Models/HomeVisit.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'health_worker_id',
        'scheduled_date',
        'address',
        'chief_complaint',
        'notes',
        'visit_notes',
        'status',
        'visited_at',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'visited_at'     => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id', 'user_id');
    }

    public function healthWorker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'health_worker_id', 'user_id');
    }
}