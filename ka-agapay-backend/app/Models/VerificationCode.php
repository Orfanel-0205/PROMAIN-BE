<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending SMS code. Read and written only through
 * App\Services\Auth\VerificationCodes, which owns the rules.
 */
class VerificationCode extends Model
{
    protected $fillable = [
        'challenge',
        'user_id',
        'purpose',
        'code_hash',
        'context',
        'expires_at',
        'attempts',
        'sends',
        'last_sent_at',
        'consumed_at',
        'ip_address',
        'user_agent',
    ];

    /** Never serialised: the hash is not the code, but it has no business leaving. */
    protected $hidden = ['code_hash', 'challenge'];

    protected $casts = [
        'context' => 'array',
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
        'sends' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
