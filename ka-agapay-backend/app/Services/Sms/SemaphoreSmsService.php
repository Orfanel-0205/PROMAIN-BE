<?php
// app/Services/Sms/SemaphoreSmsService.php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SemaphoreSmsService
{
    private string $apiKey;
    private ?string $senderName;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.semaphore.api_key');
        $this->senderName = config('services.semaphore.sendername');
        $this->baseUrl = rtrim(
            (string) config('services.semaphore.base_url', 'https://api.semaphore.co/api/v4'),
            '/'
        );
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * GET Semaphore account/credits.
     *
     * Rate limiting is handled in AdminSmsController.
     */
    public function account(): array
    {
        $this->ensureConfigured();

        $response = Http::timeout(20)->get("{$this->baseUrl}/account", [
            'apikey' => $this->apiKey,
        ]);

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 60);

            throw new RuntimeException(
                "Semaphore account check is rate limited. Try again after {$retryAfter} seconds."
            );
        }

        if (!$response->successful()) {
            throw new RuntimeException(
                'Semaphore account check failed. HTTP '
                . $response->status()
                . ': '
                . Str::limit($response->body(), 800)
            );
        }

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException('Invalid Semaphore account response.');
        }

        return $data;
    }

    /**
     * Send one SMS message to up to 1000 numbers.
     */
    public function sendBulk(array $numbers, string $message): array
    {
        $this->ensureConfigured();

        $message = trim($message);

        if ($message === '') {
            throw new RuntimeException('SMS message is empty.');
        }

        if (str_starts_with(strtoupper($message), 'TEST')) {
            throw new RuntimeException('Do not start SMS with TEST. Semaphore silently ignores TEST messages.');
        }

        $numbers = collect($numbers)
            ->map(fn ($number) => $this->normalizePhoneNumber((string) $number))
            ->filter()
            ->unique()
            ->values();

        if ($numbers->isEmpty()) {
            throw new RuntimeException('No valid recipient mobile numbers found.');
        }

        if ($numbers->count() > 1000) {
            throw new RuntimeException('Semaphore allows up to 1000 numbers per bulk API call.');
        }

        $payload = [
            'apikey' => $this->apiKey,
            'number' => $numbers->implode(','),
            'message' => $message,
        ];

        if (!empty($this->senderName)) {
            $payload['sendername'] = $this->senderName;
        }

        Log::info('[SemaphoreSmsService] Sending SMS', [
            'numbers_count' => $numbers->count(),
            'numbers' => $numbers->all(),
            'message_length' => strlen($message),
            'sendername' => $this->senderName ?: null,
        ]);

        $response = Http::asForm()
            ->timeout(60)
            ->post("{$this->baseUrl}/messages", $payload);

        Log::info('[SemaphoreSmsService] Semaphore send response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'Semaphore send failed. HTTP '
                . $response->status()
                . ': '
                . Str::limit($response->body(), 800)
            );
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new RuntimeException('Invalid Semaphore send response: ' . $response->body());
        }

        return $json;
    }

    public function normalizePhoneNumber(string $number): ?string
    {
        $number = preg_replace('/[^\d+]/', '', trim($number)) ?? '';

        if ($number === '') {
            return null;
        }

        // 09XXXXXXXXX -> 639XXXXXXXXX
        if (preg_match('/^09\d{9}$/', $number)) {
            return '63' . substr($number, 1);
        }

        // +639XXXXXXXXX -> 639XXXXXXXXX
        if (preg_match('/^\+639\d{9}$/', $number)) {
            return substr($number, 1);
        }

        // 639XXXXXXXXX
        if (preg_match('/^639\d{9}$/', $number)) {
            return $number;
        }

        return null;
    }

    private function ensureConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('SEMAPHORE_API_KEY is not configured in backend .env.');
        }
    }
}