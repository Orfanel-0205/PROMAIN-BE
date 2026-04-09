<?php
// app/Models/TelemedicineLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TelemedicineLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'loggable_type',
        'loggable_id',
        'performed_by',
        'action',
        'from_status',
        'to_status',
        'metadata',
        'performed_at',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'performed_at' => 'datetime',
    ];

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }
}