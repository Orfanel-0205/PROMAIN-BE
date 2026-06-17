<?php
// database/migrations/YYYY_MM_DD_add_online_booking_support_to_appointments_and_queue_tickets.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (!Schema::hasColumn('appointments', 'consultation_type')) {
                $table->string('consultation_type', 20)->default('onsite')->after('notes');
            }

            if (!Schema::hasColumn('appointments', 'reason')) {
                $table->text('reason')->nullable()->after('consultation_type');
            }

            if (!Schema::hasColumn('appointments', 'symptoms')) {
                $table->text('symptoms')->nullable()->after('reason');
            }

            if (!Schema::hasColumn('appointments', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('symptoms');
            }

            if (!Schema::hasColumn('appointments', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('rejection_reason');
            }

            if (!Schema::hasColumn('appointments', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('approved_at');
            }
        });

        Schema::table('queue_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('queue_tickets', 'queue_type')) {
                $table->string('queue_type', 50)
                    ->default('walk_in')
                    ->after('service_type')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('queue_tickets', 'queue_type')) {
                $table->dropColumn('queue_type');
            }
        });

        Schema::table('appointments', function (Blueprint $table) {
            foreach ([
                'scheduled_at',
                'approved_at',
                'rejection_reason',
                'symptoms',
                'reason',
                'consultation_type',
            ] as $column) {
                if (Schema::hasColumn('appointments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};