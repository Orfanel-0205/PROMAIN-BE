<?php
// database/migrations/2026_09_21_000000_create_conversation_call_signals_table.php
//
// The post box two browsers use to set up a Team Chat call between themselves.
//
// A WebRTC call is direct browser to browser: the audio and video never touch
// this server. But the two browsers have to agree how to reach each other
// first, and that handshake (an offer, an answer, and a list of network routes)
// has to travel through something they both trust. That is this table.
//
// Rows are small, short-lived and read once: each side polls for signals
// addressed to it, and a delivered signal is stamped consumed so it is never
// replayed. Anything older than an hour is rubbish by definition — a call
// setup that has not completed in that time never will.
//
// This replaces sending staff to a Jitsi window for internal calls.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversation_call_signals')) {
            return;
        }

        Schema::create('conversation_call_signals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('call_id');
            $table->unsignedBigInteger('from_user_id');

            // Null means "everyone else in this call": how a hang-up is
            // announced without naming each participant.
            $table->unsignedBigInteger('to_user_id')->nullable();

            // offer | answer | ice | hangup
            $table->string('type', 20);

            // The SDP or ICE candidate, exactly as the browser produced it.
            $table->jsonb('payload')->nullable();

            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            // The poll: "anything for me in this call that I have not read".
            $table->index(['call_id', 'to_user_id', 'consumed_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_call_signals');
    }
};
