<?php
//database/migrations/2026_06_01_204010_add_queue_number_to_event_registrations_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('event_registrations', 'queue_number')) {
                $table->string('queue_number')->nullable()->unique()->after('status');
            }

            if (!Schema::hasColumn('event_registrations', 'registered_at')) {
                $table->timestamp('registered_at')->nullable()->after('queue_number');
            }

            if (!Schema::hasColumn('event_registrations', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('registered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('event_registrations', 'queue_number')) {
                $table->dropColumn('queue_number');
            }

            if (Schema::hasColumn('event_registrations', 'registered_at')) {
                $table->dropColumn('registered_at');
            }

            if (Schema::hasColumn('event_registrations', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });
    }
};