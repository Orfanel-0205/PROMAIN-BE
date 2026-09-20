<?php
// app/Models/ConversationCallSignal.php
//
// One step of the handshake between two browsers setting up a call.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationCallSignal extends Model
{
    protected $fillable = [
        'call_id',
        'from_user_id',
        'to_user_id',
        'type',
        'payload',
        'consumed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'consumed_at' => 'datetime',
    ];
}
