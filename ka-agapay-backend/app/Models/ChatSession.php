<?php
// app/Models/ChatSession.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    protected $fillable = [
        'user_id',
        'barangay_id',
        'session_token',
        'audience',
        'title',
        'language',
        'status',
        'last_activity_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id', 'barangay_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_session_id')->orderBy('created_at');
    }

    public function recentMessages(int $limit = 10): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_session_id')
            ->latest('created_at')
            ->limit($limit);
    }
}