<?php
// database/migrations/2026_06_14_000001_fix_users_staff_approval_fields.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            $constraints = DB::select("
                SELECT conname
                FROM pg_constraint
                WHERE conrelid = 'users'::regclass
                AND contype = 'c'
                AND pg_get_constraintdef(oid) ILIKE '%account_status%'
            ");

            foreach ($constraints as $constraint) {
                DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS "' . $constraint->conname . '"');
            }

            DB::statement("ALTER TABLE users ALTER COLUMN account_status TYPE VARCHAR(30)");
            DB::statement("ALTER TABLE users ALTER COLUMN account_status SET DEFAULT 'pending'");

            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'id_verified')) {
                    $table->boolean('id_verified')->default(false)->after('account_status');
                }

                if (!Schema::hasColumn('users', 'staff_approved_by')) {
                    $table->unsignedBigInteger('staff_approved_by')->nullable()->after('id_verified');
                }

                if (!Schema::hasColumn('users', 'staff_approved_at')) {
                    $table->timestamp('staff_approved_at')->nullable()->after('staff_approved_by');
                }

                if (!Schema::hasColumn('users', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('staff_approved_at');
                }
            });
        }

        if (Schema::hasTable('user_roles')) {
            Schema::table('user_roles', function (Blueprint $table) {
                if (!Schema::hasColumn('user_roles', 'permissions')) {
                    $table->jsonb('permissions')->default('{}')->after('name');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                foreach (['staff_approved_by', 'staff_approved_at', 'rejection_reason'] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};