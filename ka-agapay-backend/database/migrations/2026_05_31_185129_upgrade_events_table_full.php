<?php
// database/migrations/2026_05_31_185129_upgrade_events_table_full.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * PostgreSQL-safe migration.
         * This avoids duplicate-column crashes by using ADD COLUMN IF NOT EXISTS.
         * It also avoids calling Schema::hasColumn after a failed ALTER statement.
         */

        DB::statement("
            CREATE TABLE IF NOT EXISTS events (
                id BIGSERIAL PRIMARY KEY,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        ");

        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS title VARCHAR(255) NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS description TEXT NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS body TEXT NULL");

        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS event_type VARCHAR(50) DEFAULT 'event'");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS category VARCHAR(100) DEFAULT 'general'");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'draft'");

        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS location VARCHAR(255) NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS target_audience VARCHAR(255) NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS barangay_target VARCHAR(255) DEFAULT 'all'");

        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS event_date TIMESTAMP NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS starts_at TIMESTAMP NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS ends_at TIMESTAMP NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS published_at TIMESTAMP NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP NULL");

        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS max_slots INTEGER NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS slots_available INTEGER NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS total_registered INTEGER DEFAULT 0");

        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS is_published BOOLEAN DEFAULT FALSE");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE");

        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS banner_url TEXT NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS banner_path TEXT NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS image_url TEXT NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS image_path TEXT NULL");

        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS created_by BIGINT NULL");
        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS archived_by BIGINT NULL");

        DB::statement("ALTER TABLE events ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");

        /*
         * Backfill values so frontend fields are not null.
         */
        DB::statement("
            UPDATE events
            SET event_type = COALESCE(NULLIF(event_type, ''), 'event')
            WHERE event_type IS NULL OR event_type = ''
        ");

        DB::statement("
            UPDATE events
            SET category = COALESCE(NULLIF(category, ''), 'general')
            WHERE category IS NULL OR category = ''
        ");

        DB::statement("
            UPDATE events
            SET status = CASE
                WHEN is_published = TRUE THEN 'published'
                WHEN status IS NULL OR status = '' THEN 'draft'
                ELSE status
            END
        ");

        DB::statement("
            UPDATE events
            SET total_registered = 0
            WHERE total_registered IS NULL
        ");

        DB::statement("
            UPDATE events
            SET slots_available = max_slots
            WHERE slots_available IS NULL
              AND max_slots IS NOT NULL
        ");

        /*
         * Drop old incompatible check constraints if they exist.
         * This prevents future event_type/status errors.
         */
        DB::statement("
            DO $$
            DECLARE
                constraint_name text;
            BEGIN
                FOR constraint_name IN
                    SELECT conname
                    FROM pg_constraint
                    WHERE conrelid = 'events'::regclass
                      AND contype = 'c'
                      AND conname ILIKE '%event_type%'
                LOOP
                    EXECUTE format('ALTER TABLE events DROP CONSTRAINT IF EXISTS %I', constraint_name);
                END LOOP;
            END $$;
        ");

        DB::statement("
            DO $$
            DECLARE
                constraint_name text;
            BEGIN
                FOR constraint_name IN
                    SELECT conname
                    FROM pg_constraint
                    WHERE conrelid = 'events'::regclass
                      AND contype = 'c'
                      AND conname ILIKE '%status%'
                LOOP
                    EXECUTE format('ALTER TABLE events DROP CONSTRAINT IF EXISTS %I', constraint_name);
                END LOOP;
            END $$;
        ");

        /*
         * Add safe check constraints.
         */
        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'events_event_type_check'
                ) THEN
                    ALTER TABLE events
                    ADD CONSTRAINT events_event_type_check
                    CHECK (event_type IN ('event', 'program', 'announcement'));
                END IF;
            END $$;
        ");

        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'events_status_check'
                ) THEN
                    ALTER TABLE events
                    ADD CONSTRAINT events_status_check
                    CHECK (status IN ('draft', 'published', 'archived', 'cancelled'));
                END IF;
            END $$;
        ");

        /*
         * Helpful indexes.
         */
        DB::statement("CREATE INDEX IF NOT EXISTS events_event_type_index ON events (event_type)");
        DB::statement("CREATE INDEX IF NOT EXISTS events_status_index ON events (status)");
        DB::statement("CREATE INDEX IF NOT EXISTS events_is_published_index ON events (is_published)");
        DB::statement("CREATE INDEX IF NOT EXISTS events_event_date_index ON events (event_date)");
        DB::statement("CREATE INDEX IF NOT EXISTS events_deleted_at_index ON events (deleted_at)");
    }

    public function down(): void
    {
        /*
         * Do not drop columns here because this is an upgrade migration
         * and other newer migrations/pages may depend on these fields.
         */
    }
};