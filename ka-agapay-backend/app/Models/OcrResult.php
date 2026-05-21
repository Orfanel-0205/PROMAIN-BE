<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrResult extends Model
{
    protected $fillable = [
        'verification_doc_id',
        'user_id',
        'extracted_name',
        'extracted_birthdate',
        'extracted_address',
        'extracted_id_number',
        'raw_ocr_response',
        'confidence_score',
        'name_match_score',
        'date_match_score',
        'overall_match',
        'ocr_status',
        'processed_at',
    ];

    protected $casts = [
        'raw_ocr_response' => 'array',
        'processed_at'     => 'datetime',
        'confidence_score' => 'float',
        'name_match_score' => 'float',
        'date_match_score' => 'float',
        'overall_match'    => 'float',
    ];

    public function verificationDocument(): BelongsTo
    {
        return $this->belongsTo(VerificationDocument::class, 'verification_doc_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
