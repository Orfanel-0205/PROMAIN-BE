<?php
// database/migrations/2026_09_20_100000_create_message_reactions_table.php
//
// Emoji reactions on Team Chat messages.
//
// Staff answer each other all day with "noted", "ok", "salamat". A reaction
// says the same thing without adding a message to a thread other people are
// trying to read — and it tells the sender their message was actually seen.
//
// One row per person per emoji per message: reacting twice with the same emoji
// removes it (a toggle), and the unique index is what makes that safe under a
// double tap.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('message_reactions')) {
            return;
        }

        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');

            // Emoji are multi-byte and some are several code points joined
            // together, so this is deliberately roomy rather than char(1).
            $table->string('emoji', 32);

            $table->timestamps();

            $table->unique(['message_id', 'user_id', 'emoji']);
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
