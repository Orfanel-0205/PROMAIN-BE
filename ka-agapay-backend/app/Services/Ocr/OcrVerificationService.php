// app/Services/Ocr/OcrVerificationService.php — processOcr() method

public function processOcr(VerificationDocument $doc): OcrResult
{
    // BUG 1 FIXED: was 'ocr_status' (not in $fillable) → silently ignored.
    // BUG 2 FIXED: file_path is NOT NULL in migration; pass the doc's id_photo_path.
    $ocrResult = OcrResult::create([
        'verification_doc_id' => $doc->id,
        'user_id'             => $doc->user_id,
        'file_path'           => $doc->id_photo_path,  // ← added (required column)
        'status'              => 'processing',          // ← was 'ocr_status' => 'processing'
    ]);

    try {
        $idText = $this->extractTextFromImage($doc->id_photo_path);
        $parsed = $this->parseIdText($idText, $doc->id_type);

        $user         = $doc->user;
        $nameMatch    = $this->calculateNameMatch($user->first_name . ' ' . $user->last_name, $parsed['name'] ?? '');
        $dateMatch    = $this->calculateDateMatch($user->residentProfile?->birth_date, $parsed['birthdate'] ?? '');
        $overallMatch = ($nameMatch + $dateMatch) / 2;

        $ocrResult->update([
            'extracted_name'      => $parsed['name'],
            'extracted_birthdate' => $parsed['birthdate'],
            'extracted_address'   => $parsed['address'],
            'extracted_id_number' => $parsed['id_number'],
            'raw_ocr_response'    => ['text' => $idText, 'parsed' => $parsed],
            'confidence_score'    => $parsed['confidence'] ?? 0.0,
            'name_match_score'    => $nameMatch,
            'date_match_score'    => $dateMatch,
            'overall_match'       => $overallMatch,
            // BUG 3 FIXED: was 'matched'/'mismatch' — not in enum.
            // Migration enum: pending | processing | approved | failed
            'status'              => $overallMatch >= 0.6 ? 'approved' : 'failed',
            'processed_at'        => now(),
        ]);

        RegistrationApproval::where('user_id', $doc->user_id)
            ->update(['ocr_result_id' => $ocrResult->id]);

    } catch (\Throwable $e) {
        $ocrResult->update([
            'status'           => 'failed',
            'raw_ocr_response' => ['error' => $e->getMessage()],
            'processed_at'     => now(),
        ]);
    }

    return $ocrResult->fresh();
}