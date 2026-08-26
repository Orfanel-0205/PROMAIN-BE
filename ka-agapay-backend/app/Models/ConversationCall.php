<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One call inside a Team Chat conversation (1:1 or group).
 *
 * A call is never hard-deleted: ending it stamps ended_at and the row remains
 * as call history.
 */
class ConversationCall extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'started_by',
        'room_name',
        'mode',
        'started_at',
        'ended_at',
        'ended_by',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'started_by' => 'integer',
        'ended_by' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationCallParticipant::class, 'call_id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
