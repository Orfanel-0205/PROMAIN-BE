<?php
// app/Jobs/ProcessOcrVerification.php

namespace App\Jobs;

use App\Models\VerificationDocument;
use App\Services\Ocr\OcrVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessOcrVerification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly VerificationDocument $document
    ) {}

    public function handle(OcrVerificationService $service): void
    {
        $service->processOcr($this->document);
    }
}