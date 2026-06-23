<?php
// app/Services/Notification/SmsService.php
namespace App\Services\Notification;

use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function send(string $mobile, string $message, string $notificationType = '', ?int $userId = null): SmsLog
    {
        $log = SmsLog::create([
            'user_id'           => $userId,
            'mobile_number'     => $mobile,
            'message'           => $message,
            'notification_type' => $notificationType,
            'provider'          => config('services.sms.provider', 'semaphore'),
            'status'            => 'pending',
        ]);

        try {
            $response = $this->callProvider($mobile, $message);

            // Semaphore returns a list: [{ "message_id": 123, "recipient": "...", "status": "Pending" }]
            $first = is_array($response) ? ($response[0] ?? []) : [];

            $messageId  = $first['message_id'] ?? null;
            $rawStatus  = $first['status'] ?? null;
            $status     = $this->mapStatus($rawStatus);

            $log->update([
                'status'              => $status,
                'provider_message_id' => $messageId,
                // Only stamp sent_at once the message is actually sent/delivered.
                'sent_at'             => $status === 'sent' ? now() : null,
                'error_message'       => $status === 'failed'
                    ? ('Semaphore status: ' . ($rawStatus ?? 'unknown'))
                    : null,
            ]);
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

        return $log;
    }

    /**
     * Calls Semaphore and returns the decoded JSON list of result objects.
     */
    private function callProvider(string $mobile, string $message): array
    {
        // Semaphore (Philippine SMS provider). Sent as form data, which Semaphore expects.
        $response = Http::asForm()->post('https://api.semaphore.co/api/v4/messages', [
            'apikey'      => config('services.sms.semaphore_key'),
            'number'      => $mobile,
            'message'     => $message,
            'sendername'  => config('services.sms.sender_name', 'KAAGAPAY'),
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SMS provider returned error: ' . $response->body());
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new \RuntimeException('Invalid Semaphore response: ' . $response->body());
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
            'sent', 'delivered', 'success', 'successful' => 'sent',
            'failed', 'error', 'undelivered', 'refunded', 'rejected' => 'failed',
            default => 'pending', // pending, queued, processing, empty, or anything unexpected
        };
    }
}
