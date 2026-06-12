<?php
// database/migrations/2026_06_11_190000_add_cms_fields_to_announcements_table.php

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
            if (!Schema::hasColumn('announcements', 'category')) {
                $table->string('category', 50)->default('general')->after('body');
            }

            if (!Schema::hasColumn('announcements', 'banner_path')) {
                $table->string('banner_path', 500)->nullable()->after('status');
            }

            if (!Schema::hasColumn('announcements', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('published_at');
            }

            if (!Schema::hasColumn('announcements', 'archived_by')) {
                $table->unsignedBigInteger('archived_by')->nullable()->after('archived_at');
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
            if (Schema::hasColumn('announcements', 'category')) {
                $table->dropColumn('category');
            }

            if (Schema::hasColumn('announcements', 'banner_path')) {
                $table->dropColumn('banner_path');
            }

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
