<?php
// database/migrations/xxxx_xx_xx_xxxxxx_add_missing_cms_columns_to_events_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'event_type')) {
                $table->string('event_type', 50)->default('event')->after('description');
            }

            if (!Schema::hasColumn('events', 'category')) {
                $table->string('category', 100)->nullable()->after('event_type');
            }

            if (!Schema::hasColumn('events', 'event_date')) {
                $table->timestamp('event_date')->nullable()->after('category');
            }

            if (!Schema::hasColumn('events', 'starts_at')) {
                $table->timestamp('starts_at')->nullable()->after('event_date');
            }

            if (!Schema::hasColumn('events', 'ends_at')) {
                $table->timestamp('ends_at')->nullable()->after('starts_at');
            }

            if (!Schema::hasColumn('events', 'location')) {
                $table->string('location')->nullable()->after('ends_at');
            }

            if (!Schema::hasColumn('events', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('location');
            }

            if (!Schema::hasColumn('events', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }

            if (!Schema::hasColumn('events', 'barangay_target')) {
                $table->string('barangay_target', 150)->default('all')->after('longitude');
            }

            if (!Schema::hasColumn('events', 'target_audience')) {
                $table->string('target_audience')->nullable()->after('barangay_target');
            }

            if (!Schema::hasColumn('events', 'tags')) {
                $table->json('tags')->nullable()->after('target_audience');
            }

            if (!Schema::hasColumn('events', 'max_slots')) {
                $table->integer('max_slots')->nullable()->after('tags');
            }

            if (!Schema::hasColumn('events', 'slots_available')) {
                $table->integer('slots_available')->nullable()->after('max_slots');
            }

            if (!Schema::hasColumn('events', 'banner_image')) {
                $table->string('banner_image')->nullable()->after('slots_available');
            }

            if (!Schema::hasColumn('events', 'sms_summary')) {
                $table->string('sms_summary', 160)->nullable()->after('banner_image');
            }

            if (!Schema::hasColumn('events', 'priority')) {
                $table->string('priority', 20)->default('normal')->after('sms_summary');
            }

            if (!Schema::hasColumn('events', 'visibility')) {
                $table->string('visibility', 20)->default('public')->after('priority');
            }

            if (!Schema::hasColumn('events', 'is_published')) {
                $table->boolean('is_published')->default(false)->after('visibility');
            }

            if (!Schema::hasColumn('events', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('is_published');
            }

            if (!Schema::hasColumn('events', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('published_at');
            }
        });

        // Copy old date into starts_at if event_date exists but starts_at is empty.
        if (
            Schema::hasColumn('events', 'event_date') &&
            Schema::hasColumn('events', 'starts_at')
        ) {
            DB::statement('UPDATE events SET starts_at = event_date WHERE starts_at IS NULL AND event_date IS NOT NULL');
        }

        // PostgreSQL-safe indexes
        try {
            DB::statement('CREATE INDEX IF NOT EXISTS events_event_type_index ON events (event_type)');
        } catch (Throwable $e) {
            //
        }

        try {
            DB::statement('CREATE INDEX IF NOT EXISTS events_is_published_index ON events (is_published)');
        } catch (Throwable $e) {
            //
        }

        try {
            DB::statement('CREATE INDEX IF NOT EXISTS events_published_at_index ON events (published_at)');
        } catch (Throwable $e) {
            //
        }

        try {
            DB::statement('CREATE INDEX IF NOT EXISTS events_starts_at_index ON events (starts_at)');
        } catch (Throwable $e) {
            //
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $columns = [
                'event_type',
                'category',
                'starts_at',
                'ends_at',
                'latitude',
                'longitude',
                'barangay_target',
                'target_audience',
                'tags',
                'max_slots',
                'slots_available',
                'banner_image',
                'sms_summary',
                'priority',
                'visibility',
                'is_published',
                'published_at',
                'created_by',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};