<?php
// database/migrations/2026_07_07_000000_make_users_uniqueness_soft_delete_aware.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Make the users mobile_number / email uniqueness soft-delete aware.
     *
     * create_users_table added FULL unique indexes on these columns, before
     * deleted_at existed. After Part 6 (archive, never delete), a soft-deleted
     * account still physically occupied its number/email, so the DB rejected any
     * new registration reusing a released number even though the old account was
     * archived — a real person was permanently locked out. We rebuild the SAME
     * named indexes as PARTIAL unique indexes scoped to active rows only
     * (deleted_at IS NULL). The names are kept identical on purpose so
     * AdminUserController::handleUserUniqueViolation() keeps mapping the
     * duplicate-key error to a friendly message.
     *
     * Safe to run with --force: active rows were already globally unique under
     * the old index, so the partial index builds without conflict.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // Partial (filtered) unique indexes are a PostgreSQL feature; the app
            // runs on Postgres. No-op elsewhere so the migration stays portable.
            return;
        }

        // mobile_number: full unique -> partial unique (active accounts only).
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_mobile_number_unique');
        DB::statement('DROP INDEX IF EXISTS users_mobile_number_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_mobile_number_unique ON users (mobile_number) WHERE deleted_at IS NULL');

        // email (nullable): partial unique also naturally permits many NULLs.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
        DB::statement('DROP INDEX IF EXISTS users_email_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users (email) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Restore the original full unique indexes. This can fail if a number or
        // email is now shared by an archived + active account created after the
        // up() migration — acceptable for a manual rollback.
        DB::statement('DROP INDEX IF EXISTS users_mobile_number_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_mobile_number_unique ON users (mobile_number)');

        DB::statement('DROP INDEX IF EXISTS users_email_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users (email)');
    }
};
