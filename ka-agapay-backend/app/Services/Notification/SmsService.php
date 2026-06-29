<?php
// app/Services/Notification/SmsService.php
namespace App\Services\Notification;

use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SmsService
{
    public function send(string $mobile, string $message, string $notificationType = '', ?int $userId = null): SmsLog
    {
        $log = SmsLog::create([
            'user_id'           => $userId,
            'mobile_number'     => $mobile,
            'message'           => $message,
            'notification_type' => $notificationType,
            'provider'          => $this->providerName(),
            'status'            => 'pending',
        ]);

        try {
            $apiKey = $this->apiKey();

            // Fail fast with a clear message if the key is missing — otherwise
            // Semaphore returns a confusing error and message_id stays null.
            if ($apiKey === '') {
                $log->update([
                    'status'        => 'failed',
                    'error_message' => 'SEMAPHORE_API_KEY is not configured on the server.',
                ]);

                Log::error('SMS send failed: Semaphore API key not configured', [
                    'log_id' => $log->id,
                ]);

                return $log->fresh() ?? $log;
            }

            $response = $this->callProvider($apiKey, $mobile, $message);

            // Semaphore returns a list: [{ "message_id": 123, "recipient": "...", "status": "Pending" }]
            $first = is_array($response) ? ($response[0] ?? []) : [];

            $messageId = $first['message_id'] ?? null;
            $rawStatus = $first['status'] ?? null;

            // A message_id in the response means Semaphore accepted the SMS and
            // queued it for delivery. Their initial status "Pending" reflects their
            // internal queue state, not a failure — treat acceptance as 'sent'.
            $status = ($messageId !== null) ? 'sent' : $this->mapStatus($rawStatus);

            $updates = [
                'status'              => $status,
                'provider_message_id' => $messageId,
                'sent_at'             => $status === 'sent' ? now() : null,
                'error_message'       => $status === 'failed'
                    ? ('Semaphore status: ' . ($rawStatus ?? 'unknown'))
                    : null,
            ];

            if (Schema::hasColumn('sms_logs', 'raw_response')) {
                $updates['raw_response'] = $response;
            }

            $log->update($updates);
        } catch (\Throwable $e) {
            Log::error('SMS send failed', [
                'mobile'  => $mobile,
                'error'   => $e->getMessage(),
                'log_id'  => $log->id,
            ]);

            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $log->fresh() ?? $log;
    }

    /**
     * Calls Semaphore and returns the decoded JSON list of result objects.
     */
    private function callProvider(string $apiKey, string $mobile, string $message): array
    {
        $baseUrl = rtrim(
            (string) config('services.semaphore.base_url', 'https://api.semaphore.co/api/v4'),
            '/'
        );

        // Semaphore expects form-encoded data.
        $response = Http::asForm()->timeout(30)->post("{$baseUrl}/messages", [
            'apikey'     => $apiKey,
            'number'     => $mobile,
            'message'    => $message,
            'sendername' => $this->senderName(),
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                'SMS provider returned HTTP ' . $response->status() . ': ' . $response->body()
            );
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new \RuntimeException('Invalid Semaphore response: ' . $response->body());
        }

        // Semaphore validation errors arrive as a 2xx body shaped like
        // {"number":["The number field is required."]} — surface them as failures.
        if (!array_is_list($json)) {
            throw new \RuntimeException('Semaphore rejected the request: ' . $response->body());
        }

        return $json;
    }

    /**
     * Maps the Semaphore status string to our sms_logs status vocabulary.
     * Unknown values fall back to "pending" so we never falsely claim "sent".
     */
    private function mapStatus(mixed $status): string
    {
        $value = strtolower(trim((string) $status));

        return match ($value) {
            'pending', 'queued' => 'pending',
            'sent', 'delivered', 'success', 'successful' => 'sent',
            'failed', 'error', 'undelivered', 'refunded', 'rejected' => 'failed',
            default => 'pending', // pending, queued, processing, empty, or anything unexpected
        };
    }

    /**
     * API key — primary canonical config, with a fallback to the older key path
     * so existing deployments keep working.
     */
    private function apiKey(): string
    {
        return trim((string) (
            config('services.semaphore.api_key')
            ?: config('services.sms.semaphore_key')
            ?: env('SEMAPHORE_API_KEY')
        ));
    }

    private function senderName(): string
    {
        $name = trim((string) (
            config('services.semaphore.sendername')
            ?: config('services.sms.sender_name')
            ?: env('SEMAPHORE_SENDER_NAME')
            ?: 'KAAGAPAY'
        ));

        return $name !== '' ? $name : 'KAAGAPAY';
    }

    private function providerName(): string
    {
        return (string) (
            config('services.sms_provider')
            ?: config('services.sms.provider')
            ?: 'semaphore'
        );
    }
}
