<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistration extends Model
{
    protected $fillable = [
        'event_id',
        'user_id',
        'status',
        'queue_number',
        'registered_at',
        'cancelled_at',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public const STATUS_REGISTERED = 'registered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ATTENDED = 'attended';
    public const STATUS_NO_SHOW = 'no_show';

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}