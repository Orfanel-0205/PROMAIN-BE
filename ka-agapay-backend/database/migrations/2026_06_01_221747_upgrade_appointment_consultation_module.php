<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (Schema::hasTable('appointments')) {
            $this->makeStatusColumnFlexible('appointments', $driver, 'pending');

            Schema::table('appointments', function (Blueprint $table) {
                if (!Schema::hasColumn('appointments', 'consultation_type')) {
                    $table->string('consultation_type', 20)->default('onsite')->after('purpose');
                }

                if (!Schema::hasColumn('appointments', 'reason')) {
                    $table->text('reason')->nullable()->after('consultation_type');
                }

                if (!Schema::hasColumn('appointments', 'symptoms')) {
                    $table->text('symptoms')->nullable()->after('reason');
                }

                if (!Schema::hasColumn('appointments', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('notes');
                }

                if (!Schema::hasColumn('appointments', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('rejection_reason');
                }

                if (!Schema::hasColumn('appointments', 'scheduled_at')) {
                    $table->timestamp('scheduled_at')->nullable()->after('approved_at');
                }
            });
        }

        if (Schema::hasTable('consultations')) {
            $this->makeStatusColumnFlexible('consultations', $driver, 'open');

            Schema::table('consultations', function (Blueprint $table) {
                if (!Schema::hasColumn('consultations', 'appointment_id')) {
                    $table->foreignId('appointment_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('appointments')
                        ->nullOnDelete();
                }

                if (!Schema::hasColumn('consultations', 'subjective')) {
                    $table->text('subjective')->nullable()->after('status');
                }

                if (!Schema::hasColumn('consultations', 'objective')) {
                    $table->text('objective')->nullable()->after('subjective');
                }

                if (!Schema::hasColumn('consultations', 'assessment')) {
                    $table->text('assessment')->nullable()->after('objective');
                }

                if (!Schema::hasColumn('consultations', 'plan')) {
                    $table->text('plan')->nullable()->after('assessment');
                }

                if (!Schema::hasColumn('consultations', 'notes')) {
                    $table->text('notes')->nullable()->after('plan');
                }

                if (!Schema::hasColumn('consultations', 'started_at')) {
                    $table->timestamp('started_at')->nullable()->after('notes');
                }

                if (!Schema::hasColumn('consultations', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('started_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('consultations')) {
            Schema::table('consultations', function (Blueprint $table) {
                foreach ([
                    'completed_at',
                    'started_at',
                    'notes',
                    'plan',
                    'assessment',
                    'objective',
                    'subjective',
                    'appointment_id',
                ] as $column) {
                    if (Schema::hasColumn('consultations', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('appointments')) {
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
    }

    private function makeStatusColumnFlexible(string $table, string $driver, string $default): void
    {
        if (!Schema::hasColumn($table, 'status')) {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_status_check");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN status TYPE VARCHAR(30)");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN status SET DEFAULT '{$default}'");
            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY status VARCHAR(30) NOT NULL DEFAULT '{$default}'");
        }
    }
};
