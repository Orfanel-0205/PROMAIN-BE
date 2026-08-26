<?php
// database/migrations/2026_08_27_000000_add_team_chat_presence_and_calls.php
//
// Team Chat round 2 — presence indicator + in-chat calling.
//
// Additive-only and fully guarded (hasTable / hasColumn), so re-running against
// live production data is safe and nothing existing is touched or dropped.
//
// Read receipts deliberately need NO schema change: conversation_participants
// already carries last_read_message_id, which is all a "seen" marker requires.
//
// FK note: this follows the existing team-chat tables and uses plain
// unsignedBigInteger columns rather than constrained() foreign keys. The older
// queue_counters migration constrained an rhu_id to barangays(barangay_id) and
// that mismatch still bites; these tables stay out of that trap.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Presence -------------------------------------------------------
        // A lightweight heartbeat column. It is refreshed from polls the app
        // ALREADY makes (the sidebar unread-count poll and the Team Chat
        // updates poll), so presence costs zero extra requests against the
        // per-user rate limit. "Online" is derived as "active within N minutes".
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'last_active_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_active_at')->nullable()->index();
            });
        }

        // --- Calls ----------------------------------------------------------
        // One row per call in a conversation. A call is never hard-deleted:
        // ending it stamps ended_at and the row stays as history.
        if (!Schema::hasTable('conversation_calls')) {
            Schema::create('conversation_calls', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('started_by');

                // Jitsi room name for this call, built by WebRtcService from the
                // same configured provider/domain telemedicine already uses.
                $table->string('room_name', 190);

                // 'audio' or 'video' — what the caller started.
                $table->string('mode', 20)->default('video');

                $table->timestamp('started_at')->nullable();

                // Set when the call finishes. NULL = still ringing/ongoing, which
                // is exactly what the "is there an active call" poll looks for.
                $table->timestamp('ended_at')->nullable();
                $table->unsignedBigInteger('ended_by')->nullable();

                $table->timestamps();
                $table->softDeletes();

                // The hot query is "active call(s) for these conversations".
                $table->index(['conversation_id', 'ended_at']);
                $table->index('ended_at');
            });
        }

        // Who actually picked up. Kept separate so a group call can record each
        // participant's join/leave without touching the call row.
        if (!Schema::hasTable('conversation_call_participants')) {
            Schema::create('conversation_call_participants', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('call_id');
                $table->unsignedBigInteger('user_id');

                $table->timestamp('joined_at')->nullable();
                $table->timestamp('left_at')->nullable();

                // 'ringing' | 'joined' | 'declined' | 'missed'
                $table->string('status', 20)->default('ringing');

                $table->timestamps();

                $table->unique(['call_id', 'user_id']);
                $table->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally conservative: drop only what this migration created and
        // never the users column, which other features may come to rely on.
        Schema::dropIfExists('conversation_call_participants');
        Schema::dropIfExists('conversation_calls');
    }
};
