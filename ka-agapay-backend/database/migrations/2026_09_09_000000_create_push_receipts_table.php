<?php
// database/migrations/2026_09_09_000000_create_push_receipts_table.php
//
// Closes the blind spot that hid a total push-notification outage.
//
// INCIDENT (2026-09-09). Push notifications were not being delivered to any
// device, and every log in the system said they were. Traced end to end:
//
//   Laravel -> Expo   HTTP 200, ticket returned with status "ok"
//   Expo    -> FCM    receipt reported DeviceNotRegistered
//   device            nothing ever arrived
//
// ExpoPushService logged "Push notification accepted by Expo" on the ticket and
// stopped there. That is the trap: an Expo *ticket* only means Expo queued the
// message. Whether Google actually accepted it is reported minutes later, in a
// separate *receipt*, which nothing in this codebase had ever fetched. So the
// only record of a total outage was a log line claiming success.
//
// (The underlying cause that day was a stale FCM registration cached by Google
// Play Services on the test handset -- a device fault, not an app fault. The
// point of this table is that it took hours to find something a receipt would
// have named immediately.)
//
// One row per ticket. The row is written when Expo accepts the message and
// updated when its receipt is read, so an unresolved row is itself evidence:
// status 'pending' with a checked_at that never arrived means the receipt was
// never collected, which is a different failure from a receipt that came back
// with an error.
//
// Receipts are only retained by Expo for about 24 hours, so rows older than
// that can never be resolved and are pruned by push:check-receipts.
//
// Additive-only and guarded -- creates a new table, alters nothing existing.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_receipts')) {
            return;
        }

        Schema::create('push_receipts', function (Blueprint $table) {
            $table->id();

            // Expo's ticket id, the handle used to fetch the receipt later.
            // Unique so a retry of the same send cannot enqueue it twice.
            $table->string('ticket_id', 191)->unique();

            // Which device row this was addressed to, so a DeviceNotRegistered
            // receipt can deactivate exactly that token. Nullable because a send
            // may target a token that is not (or no longer) in the table, and a
            // deleted device must not delete the evidence of what happened.
            $table->unsignedBigInteger('user_device_token_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // Context, kept so an error can be read without joining back to
            // application state that may since have changed.
            $table->string('channel_id', 100)->nullable();
            $table->string('notification_type', 100)->nullable();

            // pending -> ok | error. 'pending' means the receipt has not been
            // read yet, NOT that delivery is in doubt.
            $table->string('status', 20)->default('pending');

            // Expo's machine-readable error ("DeviceNotRegistered",
            // "MessageTooBig", "MessageRateExceeded", "MismatchSenderId",
            // "InvalidCredentials") and its human-readable message.
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('sent_at');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            // The collector's own query: unresolved rows, oldest first.
            $table->index(['status', 'sent_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_receipts');
    }
};
