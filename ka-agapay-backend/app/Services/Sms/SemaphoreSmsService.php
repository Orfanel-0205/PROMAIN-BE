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

    public function providerName(): string
    {
        return 'semaphore';
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function account(): array
    {
        $this->ensureConfigured();

        $response = Http::timeout(20)->get("{$this->baseUrl}/account", [
            'apikey' => $this->apiKey,
        ]);

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

    public function sendBulk(array $numbers, string $message): array
    {
        $this->ensureConfigured();

        $message = trim($message);

        if ($message === '') {
            throw new RuntimeException('SMS message is empty.');
        }

        if (str_starts_with(strtoupper($message), 'TEST')) {
            throw new RuntimeException('Do not start SMS messages with TEST. Semaphore may silently ignore them.');
        }

        $numbers = collect($numbers)
            ->map(fn ($number) => $this->normalizePhoneNumber((string) $number))
            ->filter()
            ->unique()
            ->values();

        if ($numbers->isEmpty()) {
            throw new RuntimeException('No valid Philippine mobile numbers found.');
        }

        if ($numbers->count() > 1000) {
            throw new RuntimeException('Maximum 1000 recipients allowed per Semaphore request.');
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
            'message_length' => strlen($message),
            'sendername' => $this->senderName,
        ]);

        $response = Http::asForm()
            ->timeout(60)
            ->post("{$this->baseUrl}/messages", $payload);

        Log::info('[SemaphoreSmsService] Semaphore response', [
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

        return collect($json)
            ->map(function ($item) use ($message) {
                return [
                    'message_id' => data_get($item, 'message_id'),
                    'recipient' => data_get($item, 'recipient') ?? data_get($item, 'number'),
                    'message' => data_get($item, 'message') ?? $message,
                    'sender_name' => data_get($item, 'sender_name'),
                    'network' => data_get($item, 'network'),
                    'status' => $this->normalizeReturnedStatus(data_get($item, 'status') ?? 'queued'),
                    'type' => data_get($item, 'type'),
                    'source' => data_get($item, 'source') ?? 'api',
                    'raw_response' => $item,
                ];
            })
            ->values()
            ->all();
    }

    public function normalizePhoneNumber(string $number): ?string
    {
        $number = preg_replace('/[^\d+]/', '', trim($number)) ?? '';

        if ($number === '') {
            return null;
        }

        if (preg_match('/^09\d{9}$/', $number)) {
            return '63' . substr($number, 1);
        }

        if (preg_match('/^\+639\d{9}$/', $number)) {
            return substr($number, 1);
        }

        if (preg_match('/^639\d{9}$/', $number)) {
            return $number;
        }

        return null;
    }

    private function normalizeReturnedStatus(mixed $status): string
    {
        if ($status === true || $status === 1 || $status === '1') {
            return 'sent';
        }

        if ($status === false || $status === 0 || $status === '0') {
            return 'failed';
        }

        $value = strtolower(trim((string) $status));

        return match ($value) {
            'sent', 'success', 'successful', 'delivered', 'true' => 'sent',
            'queued', 'pending', 'processing' => 'pending',
            'failed', 'error', 'undelivered', 'refunded', 'false' => 'failed',
            default => $value ?: 'pending',
        };
    }

    private function ensureConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('SEMAPHORE_API_KEY is not configured in backend .env.');
        }
    }
}
