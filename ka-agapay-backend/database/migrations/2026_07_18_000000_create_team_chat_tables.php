<?php
// database/migrations/2026_07_18_000000_create_team_chat_tables.php
//
// Team Chat — internal staff-to-staff messaging (NOT resident-facing; separate
// from the resident chatbot's chat_sessions/chat_messages tables).
//
// Additive-only and fully guarded: every table/column/index is created behind a
// hasTable/hasColumn check so re-running against live production data is safe and
// nothing existing is touched.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $table) {
                $table->id();

                // 'dm' = 1:1 direct message, 'group' = named group thread.
                $table->string('type', 20)->default('dm');

                // Group title (null for DMs).
                $table->string('title', 150)->nullable();

                // Facility scope (1 = RHU 1, 2 = RHU 2). NULL = cross-RHU thread,
                // only creatable by a global-scope user (Super Admin).
                $table->unsignedTinyInteger('rhu_id')->nullable()->index();

                // For DMs: deterministic "dm:min-max" user-id pair so a given pair
                // can only ever have ONE direct thread. NULL for group threads.
                $table->string('dm_key', 60)->nullable()->unique();

                $table->unsignedBigInteger('created_by')->nullable();

                // Denormalized for cheap conversation-list ordering + polling.
                $table->timestamp('last_message_at')->nullable()->index();

                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('conversation_participants')) {
            Schema::create('conversation_participants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('user_id');

                // High-water mark for unread math: unread = messages with
                // id > last_read_message_id (cheap, indexed).
                $table->unsignedBigInteger('last_read_message_id')->nullable();

                $table->timestamp('muted_at')->nullable();
                // Soft "leave" — participant hidden from the thread but row kept.
                $table->timestamp('left_at')->nullable();

                $table->timestamps();

                $table->unique(['conversation_id', 'user_id']);
                $table->index(['user_id', 'conversation_id']);
            });
        }

        if (!Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('sender_id')->nullable();

                // Body may be null when the message is an image-only attachment.
                $table->text('body')->nullable();

                // Reuses the 'public' disk convention (see EventController banners).
                $table->string('attachment_path', 500)->nullable();
                $table->json('attachment_meta')->nullable();

                $table->timestamps();
                $table->softDeletes();

                // THE polling + thread index: "new messages in this conversation
                // since id X" and cursor pagination both ride this.
                $table->index(['conversation_id', 'id']);
                $table->index(['conversation_id', 'created_at']);
            });
        }

        // Indexed ILIKE for message search (Part 3). pg_trgm turns a
        // leading-wildcard ILIKE into an index scan — this is a single additive
        // index, NOT tsvector/full-text infrastructure. Postgres-only + guarded.
        if (Schema::hasTable('messages') && DB::getDriverName() === 'pgsql') {
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
                DB::statement(
                    'CREATE INDEX IF NOT EXISTS messages_body_trgm_idx '
                    . 'ON messages USING gin (body gin_trgm_ops)'
                );
            } catch (\Throwable $e) {
                // Non-fatal: search still works without the index (plain ILIKE),
                // just unindexed. Never block the migration on the extension.
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
