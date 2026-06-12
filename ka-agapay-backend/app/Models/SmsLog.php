<?php
// app/Models/SmsLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'user_id',
        'sent_by',
        'recipient_name',
        'mobile_number',
        'message',
        'mode',
        'target_filters',
        'notification_type',
        'provider',
        'provider_message_id',
        'status',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'target_filters' => 'array',
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by', 'user_id');
    }
}