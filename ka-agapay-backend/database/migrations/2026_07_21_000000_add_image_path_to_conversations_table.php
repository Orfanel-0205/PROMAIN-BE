<?php
// database/migrations/2026_07_21_000000_add_image_path_to_conversations_table.php
//
// Team Chat: optional group avatar/image. Additive + guarded — nothing existing
// is touched and DMs simply leave it null.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversations')
            && !Schema::hasColumn('conversations', 'image_path')) {
            Schema::table('conversations', function (Blueprint $table) {
                // 'public' disk path (same convention as chat attachments/banners).
                $table->string('image_path', 500)->nullable()->after('title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('conversations')
            && Schema::hasColumn('conversations', 'image_path')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }
    }
};
