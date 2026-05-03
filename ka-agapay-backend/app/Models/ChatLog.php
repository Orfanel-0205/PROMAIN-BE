<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatLog extends Model
{
    protected $fillable = [
        'user_id',
        'session_token',
        'role',
        'message',
        'intent',
        'language',
        'tokens_used',
        'response_ms',
        'was_escalated',
        'escalated_to',
    ];

    protected $casts = [
        'was_escalated' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to', 'user_id');
    }
}
