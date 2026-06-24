<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('prescriptions')) {
            return;
        }

        Schema::table('prescriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('prescriptions', 'form_type')) {
                $table->string('form_type', 30)->nullable()->default('medicine')->after('telemedicine_session_id')->index();
            }

            if (!Schema::hasColumn('prescriptions', 'clinical_impression')) {
                $table->text('clinical_impression')->nullable()->after('diagnosis_code');
            }

            if (!Schema::hasColumn('prescriptions', 'request_reason')) {
                $table->text('request_reason')->nullable()->after('clinical_impression');
            }

            if (!Schema::hasColumn('prescriptions', 'priority')) {
                $table->string('priority', 30)->nullable()->default('routine')->after('request_reason')->index();
            }

            if (!Schema::hasColumn('prescriptions', 'request_notes')) {
                $table->text('request_notes')->nullable()->after('priority');
            }

            if (!Schema::hasColumn('prescriptions', 'lab_tests')) {
                $table->jsonb('lab_tests')->nullable()->after('request_notes');
            }
        });

        DB::table('prescriptions')
            ->whereNull('form_type')
            ->update(['form_type' => 'medicine']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('prescriptions')) {
            return;
        }

        Schema::table('prescriptions', function (Blueprint $table) {
            foreach ([
                'lab_tests',
                'request_notes',
                'priority',
                'request_reason',
                'clinical_impression',
                'form_type',
            ] as $column) {
                if (Schema::hasColumn('prescriptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
