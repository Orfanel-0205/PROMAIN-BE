<?php
// app/Console/Commands/CheckPushReceipts.php
//
// Reads back what actually happened to push notifications this system reported
// as sent.
//
// WHY THIS EXISTS. Expo's send endpoint returns a *ticket*, which only means
// the message was queued. Whether Google accepted it is reported minutes later
// in a separate *receipt*. Until 2026-09-09 nothing here ever fetched one, so
// a total delivery outage looked like success in every log: tickets came back
// "ok" while every receipt said DeviceNotRegistered. This command is the part
// that asks.
//
// It is deliberately conservative about what it concludes:
//
//   - a ticket with no receipt yet is left pending, not marked failed
//   - only DeviceNotRegistered deactivates a device token; the other Expo
//     errors describe the message or our credentials, never the handset
//   - MismatchSenderId and InvalidCredentials are logged at error level,
//     because those mean push is broken for EVERY user and will not self-heal

namespace App\Console\Commands;

use App\Models\PushReceipt;
use App\Services\Notification\ExpoPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CheckPushReceipts extends Command
{
    protected $signature = 'push:check-receipts
        {--min-age=5 : Only check tickets queued at least this many minutes ago. Expo needs time to produce a receipt.}
        {--limit=1000 : Maximum tickets to check in one run.}';

    protected $description = 'Fetch Expo push receipts and record whether notifications were actually delivered.';

    public function handle(ExpoPushService $expo): int
    {
        if (!Schema::hasTable('push_receipts')) {
            $this->warn('push_receipts table is missing; run migrations first.');

            return self::SUCCESS;
        }

        $minAgeMinutes = max(0, (int) $this->option('min-age'));
        $limit = max(1, (int) $this->option('limit'));

        $this->pruneUnresolvable();

        $pending = PushReceipt::query()
            ->where('status', PushReceipt::STATUS_PENDING)
            ->where('sent_at', '<=', now()->subMinutes($minAgeMinutes))
            ->orderBy('sent_at')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No push tickets awaiting receipts.');

            return self::SUCCESS;
        }

        $summary = [
            'checked' => 0,
            'delivered' => 0,
            'failed' => 0,
            'not_ready' => 0,
            'tokens_deactivated' => 0,
        ];

        foreach ($pending->chunk(ExpoPushService::RECEIPT_BATCH_SIZE) as $chunk) {
            $receipts = $expo->fetchReceipts($chunk->pluck('ticket_id')->all());

            foreach ($chunk as $row) {
                $summary['checked']++;

                $receipt = $receipts[$row->ticket_id] ?? null;

                // No receipt yet. Expo has not finished with it; leave the row
                // pending so a later run picks it up again.
                if (!is_array($receipt)) {
                    $summary['not_ready']++;
                    continue;
                }

                if (($receipt['status'] ?? null) === 'ok') {
                    $row->update([
                        'status' => PushReceipt::STATUS_OK,
                        'checked_at' => now(),
                    ]);

                    $summary['delivered']++;
                    continue;
                }

                $errorCode = data_get($receipt, 'details.error') ?? 'UnknownError';
                $message = $receipt['message'] ?? null;

                $row->update([
                    'status' => PushReceipt::STATUS_ERROR,
                    'error_code' => $errorCode,
                    'error_message' => $message,
                    'checked_at' => now(),
                ]);

                $summary['failed']++;

                if ($this->handleError($expo, $row, $errorCode, $message)) {
                    $summary['tokens_deactivated']++;
                }
            }
        }

        Log::info('[ExpoPush] Receipt check complete.', $summary);

        $this->table(array_keys($summary), [array_values($summary)]);

        if ($summary['failed'] > 0) {
            $this->warn("{$summary['failed']} notification(s) reported as sent were NOT delivered.");
        }

        return self::SUCCESS;
    }

    /**
     * Act on a failed receipt. Returns true if a device token was deactivated.
     */
    private function handleError(
        ExpoPushService $expo,
        PushReceipt $row,
        string $errorCode,
        ?string $message
    ): bool {
        $context = [
            'ticket_id' => $row->ticket_id,
            'error' => $errorCode,
            'message' => $message,
            'user_id' => $row->user_id,
            'channel_id' => $row->channel_id,
            'type' => $row->notification_type,
        ];

        // Broken for everyone, not just this device. These do not self-heal:
        // they mean the FCM credentials or the sender id are wrong.
        if (in_array($errorCode, PushReceipt::FATAL_CONFIG_ERRORS, true)) {
            Log::error(
                '[ExpoPush] Push credentials are misconfigured; delivery is failing for ALL devices.',
                $context
            );

            return false;
        }

        if ($errorCode === PushReceipt::ERROR_DEVICE_NOT_REGISTERED) {
            $device = $row->deviceToken;

            // Only this verdict justifies retiring a token. Without the row we
            // cannot know which token it was, so there is nothing safe to do.
            if ($device && $device->token) {
                $expo->deactivateToken($device->token, PushReceipt::ERROR_DEVICE_NOT_REGISTERED);

                Log::info('[ExpoPush] Device token deactivated after DeviceNotRegistered receipt.', $context);

                return true;
            }

            Log::warning('[ExpoPush] DeviceNotRegistered receipt could not be matched to a device row.', $context);

            return false;
        }

        // MessageTooBig / MessageRateExceeded and anything new Expo introduces:
        // worth seeing, but not a reason to touch the device.
        Log::warning('[ExpoPush] Push notification was not delivered.', $context);

        return false;
    }

    /**
     * Expo keeps receipts for roughly 24 hours. Anything still pending beyond
     * that will never resolve, so close it out explicitly rather than let it
     * accumulate and make the pending count meaningless.
     */
    private function pruneUnresolvable(): void
    {
        $expired = PushReceipt::query()
            ->where('status', PushReceipt::STATUS_PENDING)
            ->where('sent_at', '<', now()->subHours(24))
            ->update([
                'status' => PushReceipt::STATUS_ERROR,
                'error_code' => 'ReceiptExpired',
                'error_message' => 'No receipt was collected within Expo\'s ~24h retention window.',
                'checked_at' => now(),
            ]);

        if ($expired > 0) {
            Log::warning('[ExpoPush] Push tickets expired before their receipts were collected.', [
                'count' => $expired,
            ]);
        }
    }
}
