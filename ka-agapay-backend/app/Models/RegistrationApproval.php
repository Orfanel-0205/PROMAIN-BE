<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationApproval extends Model
{
    protected $fillable = [
        'user_id',
        'reviewed_by',
        'ocr_result_id',
        'status',
        'review_notes',
        'rejection_reason',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function ocrResult(): BelongsTo
    {
        return $this->belongsTo(OcrResult::class, 'ocr_result_id');
    }
}
