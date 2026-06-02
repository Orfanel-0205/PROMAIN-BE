<?php
// database/migrations/2026_06_01_095101_prepare_events_for_cms_posting.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table) {
                $table->id();

                $table->string('title');
                $table->text('description')->nullable();

                $table->string('image_url')->nullable();
                $table->string('banner_image')->nullable();

                $table->string('location')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();

                $table->timestamp('starts_at')->nullable();
                $table->dateTime('event_date')->nullable();
                $table->dateTime('ends_at')->nullable();

                $table->string('event_type', 30)->default('event');
                $table->string('category', 100)->nullable();

                $table->string('barangay_target', 150)->default('all');
                $table->string('target_audience')->nullable();

                $table->json('tags')->nullable();

                $table->integer('max_slots')->nullable();
                $table->integer('slots_available')->nullable();

                $table->string('sms_summary', 160)->nullable();

                $table->string('priority', 20)->default('normal');
                $table->string('visibility', 30)->default('public');

                $table->boolean('is_published')->default(false);
                $table->timestamp('published_at')->nullable();

                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users', 'user_id')
                    ->nullOnDelete();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['is_published', 'event_date']);
                $table->index('event_type');
            });

            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'description')) {
                $table->text('description')->nullable()->after('title');
            }

            if (!Schema::hasColumn('events', 'image_url')) {
                $table->string('image_url')->nullable()->after('description');
            }

            if (!Schema::hasColumn('events', 'banner_image')) {
                $table->string('banner_image')->nullable()->after('image_url');
            }

            if (!Schema::hasColumn('events', 'location')) {
                $table->string('location')->nullable()->after('banner_image');
            }

            if (!Schema::hasColumn('events', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('location');
            }

            if (!Schema::hasColumn('events', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }

            if (!Schema::hasColumn('events', 'starts_at')) {
                $table->timestamp('starts_at')->nullable()->after('longitude');
            }

            if (!Schema::hasColumn('events', 'event_date')) {
                $table->dateTime('event_date')->nullable()->after('starts_at');
            }

            if (!Schema::hasColumn('events', 'ends_at')) {
                $table->dateTime('ends_at')->nullable()->after('event_date');
            }

            if (!Schema::hasColumn('events', 'event_type')) {
                $table->string('event_type', 30)->default('event')->after('ends_at');
            }

            if (!Schema::hasColumn('events', 'category')) {
                $table->string('category', 100)->nullable()->after('event_type');
            }

            if (!Schema::hasColumn('events', 'barangay_target')) {
                $table->string('barangay_target', 150)->default('all')->after('category');
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

            if (!Schema::hasColumn('events', 'sms_summary')) {
                $table->string('sms_summary', 160)->nullable()->after('slots_available');
            }

            if (!Schema::hasColumn('events', 'priority')) {
                $table->string('priority', 20)->default('normal')->after('sms_summary');
            }

            if (!Schema::hasColumn('events', 'visibility')) {
                $table->string('visibility', 30)->default('public')->after('priority');
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

            if (!Schema::hasColumn('events', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        /**
         * If your previous version used content_type, copy it to event_type.
         */
        if (
            Schema::hasColumn('events', 'content_type') &&
            Schema::hasColumn('events', 'event_type')
        ) {
            DB::table('events')
                ->whereNotNull('content_type')
                ->whereIn('content_type', ['event', 'program', 'announcement'])
                ->update([
                    'event_type' => DB::raw('content_type'),
                ]);
        }

        /**
         * Convert old event_type values:
         * immunization     -> program / Immunization
         * medical_mission  -> event / Medical Mission
         * health_seminar   -> event / Health Seminar
         * other            -> event / Other
         */
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

        try {
            DB::statement(
                'CREATE INDEX idx_events_published_date ON events (is_published, event_date)'
            );
        } catch (\Throwable $e) {
            //
        }

        try {
            DB::statement(
                'CREATE INDEX idx_events_type ON events (event_type)'
            );
        } catch (\Throwable $e) {
            //
        }
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $columns = [
                'image_url',
                'banner_image',
                'latitude',
                'longitude',
                'starts_at',
                'event_date',
                'ends_at',
                'event_type',
                'category',
                'barangay_target',
                'target_audience',
                'tags',
                'max_slots',
                'slots_available',
                'sms_summary',
                'priority',
                'visibility',
                'is_published',
                'published_at',
                'created_by',
                'deleted_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};