<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'rhu_id',
        'dm_key',
        'created_by',
        'last_message_at',
    ];

    protected $casts = [
        'rhu_id' => 'integer',
        'last_message_at' => 'datetime',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Deterministic DM pair key so a given pair of staff can only ever have one
     * direct thread ("dm:min-max"). Order-independent.
     */
    public static function dmKeyFor(int $userA, int $userB): string
    {
        $low = min($userA, $userB);
        $high = max($userA, $userB);

        return "dm:{$low}-{$high}";
    }
}
