<?php
// tests/Feature/Notification/PushReceiptTest.php
//
// Covers the gap that hid a total push outage: the system trusted Expo's
// ticket ("queued") and never read the receipt ("what Google did with it").
//
// The cases that matter are the ones where a wrong implementation still looks
// fine day to day -- a pending ticket being mistaken for a failure, or an
// unrelated error retiring somebody's device.

namespace Tests\Feature\Notification;

use App\Models\PushReceipt;
use App\Models\UserDeviceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(string $id, ?UserDeviceToken $device = null): PushReceipt
    {
        return PushReceipt::create([
            'ticket_id' => $id,
            'user_device_token_id' => $device?->id,
            'user_id' => $device?->user_id,
            'channel_id' => 'default',
            'notification_type' => 'test',
            'status' => PushReceipt::STATUS_PENDING,
            'sent_at' => now()->subMinutes(30),
        ]);
    }

    private function device(string $token = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]'): UserDeviceToken
    {
        return UserDeviceToken::create([
            'user_id' => null,
            'token' => $token,
            'provider' => 'expo',
            'platform' => 'android',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
    }

    public function test_a_delivered_receipt_is_recorded_as_ok(): void
    {
        $row = $this->ticket('ticket-ok');

        Http::fake([
            '*getReceipts*' => Http::response(['data' => ['ticket-ok' => ['status' => 'ok']]]),
        ]);

        $this->artisan('push:check-receipts', ['--min-age' => 0])->assertSuccessful();

        $row->refresh();
        $this->assertSame(PushReceipt::STATUS_OK, $row->status);
        $this->assertNotNull($row->checked_at);
        $this->assertNull($row->error_code);
    }

    public function test_device_not_registered_deactivates_that_device_token(): void
    {
        $device = $this->device();
        $row = $this->ticket('ticket-gone', $device);

        Http::fake([
            '*getReceipts*' => Http::response(['data' => [
                'ticket-gone' => [
                    'status' => 'error',
                    'message' => 'The recipient device is not registered with FCM.',
                    'details' => ['error' => 'DeviceNotRegistered'],
                ],
            ]]),
        ]);

        $this->artisan('push:check-receipts', ['--min-age' => 0])->assertSuccessful();

        $row->refresh();
        $this->assertSame(PushReceipt::STATUS_ERROR, $row->status);
        $this->assertSame('DeviceNotRegistered', $row->error_code);

        $device->refresh();
        $this->assertFalse($device->is_active, 'A device Expo says is gone must stop being sent to.');
    }

    public function test_a_ticket_with_no_receipt_yet_stays_pending(): void
    {
        // Expo simply omits ids it has not finished with. Treating that as
        // failure would deactivate live devices, so it must stay pending and be
        // retried by a later run.
        $device = $this->device();
        $row = $this->ticket('ticket-not-ready', $device);

        Http::fake([
            '*getReceipts*' => Http::response(['data' => []]),
        ]);

        $this->artisan('push:check-receipts', ['--min-age' => 0])->assertSuccessful();

        $row->refresh();
        $this->assertSame(PushReceipt::STATUS_PENDING, $row->status);
        $this->assertNull($row->checked_at);

        $device->refresh();
        $this->assertTrue($device->is_active, 'A pending receipt must never retire a device.');
    }

    public function test_non_device_errors_do_not_deactivate_the_device(): void
    {
        // MessageTooBig is about the payload we sent, not the handset. Retiring
        // the device here would silently lose a working recipient.
        $device = $this->device();
        $row = $this->ticket('ticket-too-big', $device);

        Http::fake([
            '*getReceipts*' => Http::response(['data' => [
                'ticket-too-big' => [
                    'status' => 'error',
                    'message' => 'Message too big',
                    'details' => ['error' => 'MessageTooBig'],
                ],
            ]]),
        ]);

        $this->artisan('push:check-receipts', ['--min-age' => 0])->assertSuccessful();

        $row->refresh();
        $this->assertSame(PushReceipt::STATUS_ERROR, $row->status);
        $this->assertSame('MessageTooBig', $row->error_code);

        $device->refresh();
        $this->assertTrue($device->is_active);
    }

    public function test_credential_errors_do_not_deactivate_devices(): void
    {
        // MismatchSenderId means push is broken for everyone. Deactivating
        // devices would destroy the token base over a server-side misconfig.
        $device = $this->device();
        $row = $this->ticket('ticket-creds', $device);

        Http::fake([
            '*getReceipts*' => Http::response(['data' => [
                'ticket-creds' => [
                    'status' => 'error',
                    'message' => 'Mismatched sender id',
                    'details' => ['error' => 'MismatchSenderId'],
                ],
            ]]),
        ]);

        $this->artisan('push:check-receipts', ['--min-age' => 0])->assertSuccessful();

        $device->refresh();
        $this->assertTrue($device->is_active);
        $this->assertSame('MismatchSenderId', $row->refresh()->error_code);
    }

    public function test_tickets_younger_than_min_age_are_left_alone(): void
    {
        $row = PushReceipt::create([
            'ticket_id' => 'ticket-fresh',
            'channel_id' => 'default',
            'status' => PushReceipt::STATUS_PENDING,
            'sent_at' => now(),
        ]);

        Http::fake();

        $this->artisan('push:check-receipts', ['--min-age' => 15])->assertSuccessful();

        $this->assertSame(PushReceipt::STATUS_PENDING, $row->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_tickets_past_expo_retention_are_closed_out(): void
    {
        // Expo keeps receipts ~24h. A ticket older than that can never resolve,
        // so leaving it pending forever makes the pending count meaningless.
        $row = PushReceipt::create([
            'ticket_id' => 'ticket-stale',
            'channel_id' => 'default',
            'status' => PushReceipt::STATUS_PENDING,
            'sent_at' => now()->subHours(30),
        ]);

        Http::fake(['*getReceipts*' => Http::response(['data' => []])]);

        $this->artisan('push:check-receipts', ['--min-age' => 0])->assertSuccessful();

        $row->refresh();
        $this->assertSame(PushReceipt::STATUS_ERROR, $row->status);
        $this->assertSame('ReceiptExpired', $row->error_code);
    }
}
