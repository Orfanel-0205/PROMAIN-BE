<?php
// app/Models/VerificationDocument.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VerificationDocument extends Model
{
    protected $fillable = [
        'user_id',
        'id_photo_path',
        'selfie_path',
        'residency_path',
        'id_type',
        'submission_ip',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function ocrResult(): HasOne
    {
        return $this->hasOne(OcrResult::class, 'verification_doc_id');
    }
}
