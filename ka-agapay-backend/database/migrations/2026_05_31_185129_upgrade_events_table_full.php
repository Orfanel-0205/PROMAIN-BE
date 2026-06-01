<?php
// database/migrations/2026_06_01_000001_upgrade_events_table_full.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {

            // -----------------------------------------------------------------
            // Image banner stored in public disk
            // -----------------------------------------------------------------
            if (!Schema::hasColumn('events', 'banner_image')) {
                $table->string('banner_image')->nullable()->after('max_slots');
            }

            // -----------------------------------------------------------------
            // Publish workflow
            // -----------------------------------------------------------------
            if (!Schema::hasColumn('events', 'is_published')) {
                $table->boolean('is_published')->default(false)->after('banner_image');
            }

            if (!Schema::hasColumn('events', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('is_published');
            }

            // -----------------------------------------------------------------
            // Main CMS posting type
            // Values:
            // event        = scheduled RHU activity
            // program      = health program post
            // announcement = general RHU announcement
            //
            // IMPORTANT:
            // Use string instead of enum to avoid painful enum migration issues.
            // -----------------------------------------------------------------
            if (!Schema::hasColumn('events', 'event_type')) {
                $table->string('event_type', 30)->default('event')->after('published_at');
            }

            // -----------------------------------------------------------------
            // Category stores specific program/event classification.
            //
            // Examples:
            // event_type = program, category = Immunization
            // event_type = event, category = Medical Mission
            // event_type = announcement, category = General Advisory
            // -----------------------------------------------------------------
            if (!Schema::hasColumn('events', 'category')) {
                $table->string('category', 100)->nullable()->after('event_type');
            }

            // -----------------------------------------------------------------
            // Barangay targeting
            // all or specific barangay name
            // -----------------------------------------------------------------
            if (!Schema::hasColumn('events', 'barangay_target')) {
                $table->string('barangay_target', 150)->default('all')->after('category');
            }

            // -----------------------------------------------------------------
            // End datetime
            // -----------------------------------------------------------------
            if (!Schema::hasColumn('events', 'ends_at')) {
                $table->dateTime('ends_at')->nullable()->after('event_date');
            }

            // -----------------------------------------------------------------
            // Available slots tracker
            // -----------------------------------------------------------------
            if (!Schema::hasColumn('events', 'slots_available')) {
                $table->integer('slots_available')->nullable()->after('max_slots');
            }

            // -----------------------------------------------------------------
            // GPS for distance calculation in mobile
            // -----------------------------------------------------------------
            if (!Schema::hasColumn('events', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('location');
            }

            if (!Schema::hasColumn('events', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }

            // -----------------------------------------------------------------
            // Description fallback
            // -----------------------------------------------------------------
            if (!Schema::hasColumn('events', 'description')) {
                $table->text('description')->nullable()->after('title');
            }

            // -----------------------------------------------------------------
            // CMS extra fields
            // -----------------------------------------------------------------
            if (!Schema::hasColumn('events', 'target_audience')) {
                $table->string('target_audience', 255)->nullable()->after('barangay_target');
            }

            if (!Schema::hasColumn('events', 'tags')) {
                $table->json('tags')->nullable()->after('target_audience');
            }

            if (!Schema::hasColumn('events', 'sms_summary')) {
                $table->string('sms_summary', 160)->nullable()->after('tags');
            }

            if (!Schema::hasColumn('events', 'priority')) {
                $table->string('priority', 20)->default('normal')->after('sms_summary');
            }

            if (!Schema::hasColumn('events', 'visibility')) {
                $table->string('visibility', 30)->default('public')->after('priority');
            }
        });

        // ---------------------------------------------------------------------
        // Convert old enum event_type column to string if it already exists.
        // This prevents errors when changing old enum values.
        // ---------------------------------------------------------------------
        if (Schema::hasColumn('events', 'event_type')) {
            try {
                DB::statement("ALTER TABLE events MODIFY event_type VARCHAR(30) NOT NULL DEFAULT 'event'");
            } catch (\Throwable $e) {
                // Ignore if database driver does not support MODIFY.
                // The column may already be a string.
            }
        }

        // ---------------------------------------------------------------------
        // Migrate old event_type values into new event_type + category structure.
        //
        // OLD:
        // immunization     -> NEW: event_type = program, category = Immunization
        // medical_mission  -> NEW: event_type = event,   category = Medical Mission
        // health_seminar   -> NEW: event_type = event,   category = Health Seminar
        // other            -> NEW: event_type = event,   category = Other
        // ---------------------------------------------------------------------
        if (
            Schema::hasColumn('events', 'event_type') &&
            Schema::hasColumn('events', 'category')
        ) {
            DB::table('events')
                ->where('event_type', 'immunization')
                ->update([
                    'event_type' => 'program',
                    'category' => 'Immunization',
                ]);

            DB::table('events')
                ->where('event_type', 'medical_mission')
                ->update([
                    'event_type' => 'event',
                    'category' => 'Medical Mission',
                ]);

            DB::table('events')
                ->where('event_type', 'health_seminar')
                ->update([
                    'event_type' => 'event',
                    'category' => 'Health Seminar',
                ]);

            DB::table('events')
                ->where('event_type', 'other')
                ->update([
                    'event_type' => 'event',
                    'category' => 'Other',
                ]);

            DB::table('events')
                ->whereNull('event_type')
                ->orWhereNotIn('event_type', ['event', 'program', 'announcement'])
                ->update([
                    'event_type' => 'event',
                ]);
        }

        // ---------------------------------------------------------------------
        // Index for mobile feed queries
        // ---------------------------------------------------------------------
        try {
            DB::statement(
                'CREATE INDEX idx_events_published_date ON events (is_published, event_date)'
            );
        } catch (\Throwable $e) {
            // Ignore if index already exists.
        }
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $columns = [
                'banner_image',
                'is_published',
                'published_at',
                'category',
                'barangay_target',
                'ends_at',
                'slots_available',
                'latitude',
                'longitude',
                'target_audience',
                'tags',
                'sms_summary',
                'priority',
                'visibility',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // Keep event_type because older parts of your app may still depend on it.
        // If you really want to remove it during rollback, uncomment this:
        //
        // Schema::table('events', function (Blueprint $table) {
        //     if (Schema::hasColumn('events', 'event_type')) {
        //         $table->dropColumn('event_type');
        //     }
        // });
    }
};