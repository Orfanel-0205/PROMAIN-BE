<?php
// database/migrations/2026_06_23_000002_add_assigned_rhu_id_to_users_table.php
//
// Adds assigned_rhu_id to users so staff/admin accounts are explicitly bound to
// RHU 1 or RHU 2. This is what "admin's RHU" means in this system — NOT a
// network IP. RHU-scoped staff only see their own RHU queue; super_admin / mho
// can see all and filter.
//
// Falls back at runtime to users.barangay_id when assigned_rhu_id is null, so
// existing accounts keep working until an admin sets the assignment.
//
// Idempotent + Postgres compatible. References barangays.barangay_id.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || Schema::hasColumn('users', 'assigned_rhu_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasTable('barangays')) {
                $table->foreignId('assigned_rhu_id')
                    ->nullable()
                    ->constrained('barangays', 'barangay_id')
                    ->nullOnDelete();
            } else {
                $table->unsignedBigInteger('assigned_rhu_id')->nullable();
            }

            $table->index('assigned_rhu_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'assigned_rhu_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropForeign(['assigned_rhu_id']);
            } catch (\Throwable) {
                // no-op
            }

            try {
                $table->dropIndex(['assigned_rhu_id']);
            } catch (\Throwable) {
                // no-op
            }

            $table->dropColumn('assigned_rhu_id');
        });
    }
};
