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

            $log->update([
                'status'              => 'sent',
                'provider_message_id' => $response['message_id'] ?? null,
                'sent_at'             => now(),
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

    private function callProvider(string $mobile, string $message): array
    {
        // Semaphore (Philippine SMS provider)
        $response = Http::post('https://api.semaphore.co/api/v4/messages', [
            'apikey'      => config('services.sms.semaphore_key'),
            'number'      => $mobile,
            'message'     => $message,
            'sendername'  => config('services.sms.sender_name', 'KAAGAPAY'),
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SMS provider returned error: ' . $response->body());
        }

        return $response->json()[0] ?? [];
    }
}
