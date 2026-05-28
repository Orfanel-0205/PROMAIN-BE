
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrResult extends Model
{
    /**
     * Mass assignable fields
     */
    protected $fillable = [

        /*
        |--------------------------------------------------------------------------
        | RELATIONSHIPS
        |--------------------------------------------------------------------------
        */

        'verification_doc_id',
        'user_id',

        /*
        |--------------------------------------------------------------------------
        | FILE METADATA
        |--------------------------------------------------------------------------
        */

        'id_type',
        'file_path',

        /*
        |--------------------------------------------------------------------------
        | OCR EXTRACTED TEXT
        |--------------------------------------------------------------------------
        */

        'extracted_text',

        /*
        |--------------------------------------------------------------------------
        | STRUCTURED EXTRACTION
        |--------------------------------------------------------------------------
        */

        'extracted_name',
        'extracted_birthdate',
        'extracted_address',
        'extracted_id_number',

        /*
        |--------------------------------------------------------------------------
        | RAW OCR RESPONSE
        |--------------------------------------------------------------------------
        */

        'raw_ocr_response',

        /*
        |--------------------------------------------------------------------------
        | OCR SCORES
        |--------------------------------------------------------------------------
        */

        'confidence_score',
        'name_match_score',
        'date_match_score',
        'overall_match',

        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        'status',

        /*
        |--------------------------------------------------------------------------
        | PROCESSING
        |--------------------------------------------------------------------------
        */

        'processed_at',
    ];

    /**
     * Attribute casting
     */
    protected $casts = [

        'raw_ocr_response' => 'array',

        'processed_at' => 'datetime',

        'confidence_score' => 'float',

        'name_match_score' => 'float',

        'date_match_score' => 'float',

        'overall_match' => 'float',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    /**
     * Linked verification document
     */
    public function verificationDocument(): BelongsTo
    {
        return $this->belongsTo(
            VerificationDocument::class,
            'verification_doc_id'
        );
    }

    /**
     * Owner of OCR result
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id',
            'user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Check if OCR is approved
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if OCR failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if OCR is processing
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }
}

