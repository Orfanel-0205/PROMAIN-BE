<?php
// database/migrations/2026_07_12_000000_create_registration_invites_table.php
//
// Panelist requirement (Sir Ayco) — staff registration links must be signed,
// unique, expiring, and one-time-use. Each row is ONE invitation: the raw
// token appears only inside the generated signed URL; the database stores a
// SHA-256 hash of it so a leaked database dump cannot be replayed as a link.
// Additive-only: no existing table or column is touched.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('registration_invites')) {
            return;
        }

        Schema::create('registration_invites', function (Blueprint $table) {
            $table->id();

            // SHA-256 of the raw 64-char token — the raw token is never stored.
            $table->string('token_hash', 64)->unique();

            // Who this invite is meant for (label only, shown to the approver)
            // and an OPTIONAL mobile lock: when set, the registration submitted
            // through this link must use this exact mobile number.
            $table->string('intended_for', 150)->nullable();
            $table->string('mobile_number', 20)->nullable();

            $table->timestamp('expires_at');

            // One-time-use bookkeeping. used_at set exactly once, inside the
            // registration transaction, under a row lock.
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('used_by_user_id')->nullable();

            // Manual invalidation by the Super Admin (soft — row is kept as
            // evidence, consistent with the archive-not-delete premise).
            $table->timestamp('revoked_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'used_at']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_invites');
    }
};
