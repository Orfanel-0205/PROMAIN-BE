<?php
// database/migrations/xxxx_xx_xx_xxxxxx_fix_events_event_type_check_constraint.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL Fix
        |--------------------------------------------------------------------------
        | Old events table has a CHECK constraint named events_event_type_check.
        | It blocks the new CMS values: event, program, announcement.
        |
        | We drop the old constraint and create a new one that accepts the CMS types.
        |--------------------------------------------------------------------------
        */

        try {
            DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_event_type_check');
        } catch (Throwable $e) {
            //
        }

        try {
            DB::statement("
                ALTER TABLE events
                ADD CONSTRAINT events_event_type_check
                CHECK (event_type IN ('event', 'program', 'announcement'))
            ");
        } catch (Throwable $e) {
            //
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize old data
        |--------------------------------------------------------------------------
        | If old rows still have old event_type values, convert them safely.
        |--------------------------------------------------------------------------
        */

        try {
            DB::statement("
                UPDATE events
                SET category = CASE
                    WHEN event_type = 'immunization' THEN 'Immunization'
                    WHEN event_type = 'medical_mission' THEN 'Medical Mission'
                    WHEN event_type = 'health_seminar' THEN 'Health Seminar'
                    WHEN event_type = 'other' THEN 'General'
                    ELSE category
                END
                WHERE event_type IN ('immunization', 'medical_mission', 'health_seminar', 'other')
            ");
        } catch (Throwable $e) {
            //
        }

        try {
            DB::statement("
                UPDATE events
                SET event_type = CASE
                    WHEN event_type IN ('immunization', 'medical_mission', 'health_seminar', 'other') THEN 'program'
                    WHEN event_type IS NULL OR event_type = '' THEN 'event'
                    ELSE event_type
                END
            ");
        } catch (Throwable $e) {
            //
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_event_type_check');
        } catch (Throwable $e) {
            //
        }

        try {
            DB::statement("
                ALTER TABLE events
                ADD CONSTRAINT events_event_type_check
                CHECK (event_type IN ('immunization', 'medical_mission', 'health_seminar', 'other'))
            ");
        } catch (Throwable $e) {
            //
        }
    }
};