<?php
// app/Services/Notification/ExpoPushService.php

namespace App\Services\Notification;

use App\Models\PushReceipt;
use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ExpoPushService
{
    private const EXPO_PUSH_ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    /**
     * Where receipts are read back from. A ticket says Expo queued the message;
     * only the receipt says whether Google accepted it.
     */
    public const EXPO_RECEIPT_ENDPOINT = 'https://exp.host/--/api/v2/push/getReceipts';

    /**
     * Expo accepts up to 1000 ticket ids per receipt request. Kept well under
     * that so one oversized batch cannot fail the whole collection run.
     */
    public const RECEIPT_BATCH_SIZE = 300;

    public function sendToUser(
        int $userId,
        string $title,
        string $body,
        array $data = [],
        string $channelId = 'queue-alerts'
    ): int {
        if (!Schema::hasTable('user_device_tokens')) {
            return 0;
        }

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

            /*
             * "Accepted by Expo" is NOT "delivered". The ticket only means Expo
             * queued the message; whether Google took it is reported minutes
             * later in a separate receipt. Treating this line as success is
             * exactly what hid a total push outage on 2026-09-09, when every
             * ticket came back ok and every receipt said DeviceNotRegistered.
             *
             * Record the ticket so push:check-receipts can go and ask.
             */
            $ticketId = $ticket['id'] ?? null;

            if ($ticketId) {
                $this->recordTicket(
                    ticketId: (string) $ticketId,
                    token: $token,
                    channelId: $channelId,
                    data: $data
                );
            }

            Log::info('[ExpoPush] Push notification queued by Expo (delivery not yet confirmed).', [
                'channelId' => $channelId,
                'type' => $data['type'] ?? null,
                'ticket_id' => $ticketId,
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

    /**
     * Persist a queued ticket so its receipt can be collected later.
     *
     * Deliberately best-effort: a send that reached Expo must not be reported
     * as failed just because bookkeeping did not write. The row is the only
     * record that a receipt is owed, so losing one costs visibility, never
     * delivery.
     */
    private function recordTicket(
        string $ticketId,
        string $token,
        string $channelId,
        array $data
    ): void {
        if (!Schema::hasTable('push_receipts')) {
            return;
        }

        try {
            $device = Schema::hasTable('user_device_tokens')
                ? UserDeviceToken::query()->where('token', $token)->first()
                : null;

            PushReceipt::query()->updateOrCreate(
                ['ticket_id' => $ticketId],
                [
                    'user_device_token_id' => $device?->id,
                    'user_id' => $device?->user_id,
                    'channel_id' => $channelId,
                    'notification_type' => $data['type'] ?? null,
                    'status' => PushReceipt::STATUS_PENDING,
                    'sent_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[ExpoPush] Could not record push ticket for receipt checking.', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ask Expo what actually happened to a batch of tickets.
     *
     * Returns the raw receipt map keyed by ticket id. A ticket id that is
     * absent from the response has no receipt yet -- that is normal for a
     * recent send and must not be read as failure.
     *
     * @param  array<int, string>  $ticketIds
     * @return array<string, array<string, mixed>>
     */
    public function fetchReceipts(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->post(self::EXPO_RECEIPT_ENDPOINT, ['ids' => array_values($ticketIds)]);

            if (!$response->successful()) {
                Log::warning('[ExpoPush] Receipt endpoint returned failure.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'count' => count($ticketIds),
                ]);

                return [];
            }

            $data = $response->json('data');

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            Log::warning('[ExpoPush] Receipt fetch exception.', [
                'message' => $e->getMessage(),
                'count' => count($ticketIds),
            ]);

            return [];
        }
    }

    /**
     * Deactivate a device token that Expo has told us is gone.
     *
     * Public so the receipt collector can act on DeviceNotRegistered, which is
     * where that verdict actually arrives -- the ticket almost never carries it.
     */
    public function deactivateToken(string $token, string $reason): void
    {
        $this->markTokenFailed($token, $reason);
    }

    private function markTokenFailed(string $token, string $reason): void
    {
        if (!Schema::hasTable('user_device_tokens')) {
            return;
        }

        $values = [
            'is_active' => false,
        ];

        if (Schema::hasColumn('user_device_tokens', 'failed_at')) {
            $values['failed_at'] = now();
        }

        if (Schema::hasColumn('user_device_tokens', 'failure_reason')) {
            $values['failure_reason'] = $reason;
        }

        UserDeviceToken::query()
            ->where('token', $token)
            ->update($values);
    }
}