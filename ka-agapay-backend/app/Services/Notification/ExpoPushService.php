<?php
// app/Services/Notification/ExpoPushService.php

namespace App\Services\Notification;

use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushService
{
    private const EXPO_PUSH_ENDPOINT = 'https://exp.host/--/api/v2/push/send';

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
        if (!$this->isExpoToken($token)) {
            Log::warning('[ExpoPush] Invalid Expo token skipped.', [
                'token_prefix' => substr($token, 0, 18),
                'channelId' => $channelId,
            ]);

            return false;
        }

        try {
            $payload = [
                'to' => $token,
                'sound' => 'default',
                'title' => $title,
                'body' => $body,
                'priority' => 'high',
                'channelId' => $channelId,
                'data' => $data,
            ];

            $response = Http::timeout(8)
                ->acceptJson()
                ->post(self::EXPO_PUSH_ENDPOINT, $payload);

            if (!$response->successful()) {
                Log::warning('[ExpoPush] Expo endpoint returned failure.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'channelId' => $channelId,
                    'type' => $data['type'] ?? null,
                ]);

                return false;
            }

            $responseData = $response->json();
            $ticket = $responseData['data'] ?? [];

            if (is_array($ticket) && array_key_exists(0, $ticket)) {
                $ticket = $ticket[0] ?? [];
            }

            if (($ticket['status'] ?? null) === 'error') {
                $errorCode = data_get($ticket, 'details.error')
                    ?? ($ticket['message'] ?? 'expo_error');

                Log::warning('[ExpoPush] Expo ticket rejected notification.', [
                    'error' => $errorCode,
                    'message' => $ticket['message'] ?? null,
                    'channelId' => $channelId,
                    'type' => $data['type'] ?? null,
                ]);

                if ($errorCode === 'DeviceNotRegistered') {
                    $this->markTokenFailed($token, $errorCode);
                }

                return false;
            }

            Log::info('[ExpoPush] Push notification accepted by Expo.', [
                'channelId' => $channelId,
                'type' => $data['type'] ?? null,
                'ticket_id' => $ticket['id'] ?? null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[ExpoPush] Push notification exception.', [
                'message' => $e->getMessage(),
                'channelId' => $channelId,
                'type' => $data['type'] ?? null,
            ]);

            return false;
        }
    }

    private function isExpoToken(string $token): bool
    {
        return str_starts_with($token, 'ExponentPushToken[')
            || str_starts_with($token, 'ExpoPushToken[');
    }

    private function markTokenFailed(string $token, string $reason): void
    {
        UserDeviceToken::query()
            ->where('token', $token)
            ->update([
                'is_active' => false,
                'failed_at' => now(),
                'failure_reason' => $reason,
            ]);
    }
}
