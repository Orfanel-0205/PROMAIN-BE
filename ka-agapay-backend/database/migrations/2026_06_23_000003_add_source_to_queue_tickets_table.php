<?php
// database/migrations/2026_06_23_000003_add_source_to_queue_tickets_table.php
//
// Adds a "source" column to queue_tickets so the queue can show where a ticket
// originated: walk_in (issued at the desk) vs online_appointment (created when
// an admin approves a mobile booking). queue_type already exists but is derived
// from priority, so it can't be reused for origin.
//
// Idempotent + Postgres compatible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('queue_tickets') || Schema::hasColumn('queue_tickets', 'source')) {
            return;
        }

        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->string('source', 40)->default('walk_in')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('queue_tickets') || !Schema::hasColumn('queue_tickets', 'source')) {
            return;
        }

        Schema::table('queue_tickets', function (Blueprint $table) {
            try {
                $table->dropIndex(['source']);
            } catch (\Throwable) {
                // no-op
            }

            $table->dropColumn('source');
        });
    }
};
