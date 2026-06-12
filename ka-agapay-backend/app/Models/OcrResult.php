<?php
// app/Models/OcrResult.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrResult extends Model
{
    use HasFactory;

    // =========================================================================
    // MASS ASSIGNABLE FIELDS
    // Keep in sync with the ocr_results migration.
    // =========================================================================

    protected $fillable = [
        // Foreign keys
        'verification_doc_id',
        'user_id',

        // File metadata
        'id_type',
        'file_path',

        // OCR extracted text
        'extracted_text',

        // Structured fields parsed from OCR text
        'extracted_name',
        'extracted_birthdate',
        'extracted_address',
        'extracted_id_number',

        // Raw API payload (Google Vision / OCR.space)
        'raw_ocr_response',

        // Scoring
        'confidence_score',
        'name_match_score',
        'date_match_score',
        'overall_match',

        // Workflow status: pending | processing | approved | failed
        'status',

        // When the OCR job finished
        'processed_at',
    ];

    // =========================================================================
    // CASTS
    // =========================================================================

    protected $casts = [
        'raw_ocr_response' => 'array',
        'processed_at'     => 'datetime',
        'confidence_score' => 'float',
        'name_match_score' => 'float',
        'date_match_score' => 'float',
        'overall_match'    => 'float',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * The verification document this OCR result belongs to.
     * Nullable — OcrController uploads bypass the VerificationDocument flow.
     */
    public function verificationDocument(): BelongsTo
    {
        return $this->belongsTo(VerificationDocument::class, 'verification_doc_id');
    }

    /**
     * The user who owns this OCR result.
     * Uses 'user_id' as the local key AND the owner key (non-standard PK).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // =========================================================================
    // STATUS HELPERS
    // =========================================================================

    public function isApproved(): bool  { return $this->status === 'approved';   }
    public function isFailed(): bool    { return $this->status === 'failed';      }
    public function isProcessing(): bool{ return $this->status === 'processing';  }
    public function isPending(): bool   { return $this->status === 'pending';     }
}