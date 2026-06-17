<?php
// app/Models/NotificationPreference.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'notification_type',
        'in_app',
        'sms',
        'email',
    ];

    protected $casts = [
        'in_app' => 'boolean',
        'sms' => 'boolean',
        'email' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}