<?php
// database/migrations/2026_07_22_000000_add_content_deletion_to_messages_table.php
//
// Team Chat: "delete a message" as a soft, reversible content-redaction that
// keeps the row (and the original body) in the DB — the message stays visible in
// the thread as a "This message was deleted" placeholder, never physically
// removed. This is intentionally SEPARATE from the model's SoftDeletes
// `deleted_at` (whose global scope would hide the row and defeat the
// placeholder). Additive + guarded.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'content_deleted_at')) {
                $table->timestamp('content_deleted_at')->nullable();
            }
            if (!Schema::hasColumn('messages', 'content_deleted_by')) {
                $table->unsignedBigInteger('content_deleted_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('messages')) {
            Schema::table('messages', function (Blueprint $table) {
                if (Schema::hasColumn('messages', 'content_deleted_by')) {
                    $table->dropColumn('content_deleted_by');
                }
                if (Schema::hasColumn('messages', 'content_deleted_at')) {
                    $table->dropColumn('content_deleted_at');
                }
            });
        }
    }
};
