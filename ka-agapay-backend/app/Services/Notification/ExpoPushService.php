<?php
// app/Services/Notification/ExpoPushService.php

namespace App\Services\Notification;

use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushService
{
    public function sendToUser(
        int $userId,
        string $title,
        string $body,
        array $data = [],
        string $channelId = 'queue-alerts'
    ): int {
        $tokens = UserDeviceToken::query()
            ->where('user_id', $userId)
            ->where('provider', 'expo')
            ->where('is_active', true)
            ->pluck('token')
            ->filter()
            ->unique()
            ->values();

        $sent = 0;

        foreach ($tokens as $token) {
            if ($this->sendToToken(
                token: (string) $token,
                title: $title,
                body: $body,
                data: $data,
                channelId: $channelId
            )) {
                $sent++;
            }
        }

        return $sent;
    }

    public function sendToToken(
        string $token,
        string $title,
        string $body,
        array $data = [],
        string $channelId = 'queue-alerts'
    ): bool {
        try {
            $response = Http::timeout(5)->post('https://exp.host/--/api/v2/push/send', [
                'to' => $token,
                'sound' => 'default',
                'title' => $title,
                'body' => $body,
                'priority' => 'high',
                'channelId' => $channelId,
                'data' => $data,
            ]);

            if (!$response->successful()) {
                Log::warning('Expo push notification failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Expo push notification exception.', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
