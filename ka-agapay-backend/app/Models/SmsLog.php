<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    public const UPDATED_AT = null; // sms_logs only has created_at per migration

    protected $fillable = [
        'user_id',
        'mobile_number',
        'message',
        'notification_type',
        'provider',
        'provider_message_id',
        'status',
        'error_message',
        'sent_at',
        'delivered_at',
    ];

    protected $casts = [
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
