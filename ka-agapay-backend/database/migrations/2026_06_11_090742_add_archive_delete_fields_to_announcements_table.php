<?php
//app/database/migrations/2026_06_11_090742_add_archive_delete_fields_to_announcements_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('announcements')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            if (!Schema::hasColumn('announcements', 'status')) {
                $table->string('status', 30)->default('draft');
            }

            if (!Schema::hasColumn('announcements', 'published_at')) {
                $table->timestamp('published_at')->nullable();
            }

            if (!Schema::hasColumn('announcements', 'archived_at')) {
                $table->timestamp('archived_at')->nullable();
            }

            if (!Schema::hasColumn('announcements', 'archived_by')) {
                $table->unsignedBigInteger('archived_by')->nullable();
            }

            if (!Schema::hasColumn('announcements', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('announcements')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            if (Schema::hasColumn('announcements', 'archived_at')) {
                $table->dropColumn('archived_at');
            }

            if (Schema::hasColumn('announcements', 'archived_by')) {
                $table->dropColumn('archived_by');
            }

            if (Schema::hasColumn('announcements', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};