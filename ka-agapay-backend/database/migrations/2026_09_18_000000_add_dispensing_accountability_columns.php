<?php
// database/migrations/2026_09_18_000000_add_dispensing_accountability_columns.php
//
// Dispensing accountability (docs/OPERATIONS.md §9 "Dispensing"):
//
//   prescriptions.released_at / released_by
//     Who handed the prescription to the patient, and when. For telemedicine
//     prescriptions filled at an outside pharmacy this is the only record the
//     RHU has, because it never sees what the pharmacy gives.
//
//   prescription_dispensing_logs.received_by_name / received_by_relationship
//     Who took the medicine: the patient, or someone collecting for them.
//
// Additive and idempotent.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prescriptions')) {
            Schema::table('prescriptions', function (Blueprint $table) {
                if (!Schema::hasColumn('prescriptions', 'released_at')) {
                    $table->timestamp('released_at')->nullable();
                }

                if (!Schema::hasColumn('prescriptions', 'released_by')) {
                    $table->foreignId('released_by')
                        ->nullable()
                        ->constrained('users', 'user_id')
                        ->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('prescription_dispensing_logs')) {
            Schema::table('prescription_dispensing_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('prescription_dispensing_logs', 'received_by_name')) {
                    $table->string('received_by_name', 150)->nullable();
                }

                if (!Schema::hasColumn('prescription_dispensing_logs', 'received_by_relationship')) {
                    $table->string('received_by_relationship', 50)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prescriptions', 'released_by')) {
            Schema::table('prescriptions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('released_by');
            });
        }

        if (Schema::hasColumn('prescriptions', 'released_at')) {
            Schema::table('prescriptions', function (Blueprint $table) {
                $table->dropColumn('released_at');
            });
        }

        foreach (['received_by_name', 'received_by_relationship'] as $column) {
            if (Schema::hasColumn('prescription_dispensing_logs', $column)) {
                Schema::table('prescription_dispensing_logs', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
