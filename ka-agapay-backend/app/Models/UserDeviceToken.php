<?php
// app/Models/UserDeviceToken.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDeviceToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'provider',
        'platform',
        'device_name',
        'app_version',
        'channel_id',
        'is_active',
        'last_seen_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
