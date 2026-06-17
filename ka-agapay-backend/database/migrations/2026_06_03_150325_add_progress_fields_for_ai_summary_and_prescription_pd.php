<?php
//database/migrations/2026_06_03_150325_add_progress_fields_for_ai_summary_and_prescription_pd.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('consultations')) {
            Schema::table('consultations', function (Blueprint $table) {
                if (!Schema::hasColumn('consultations', 'transcript')) {
                    $table->longText('transcript')->nullable()->after('notes');
                }
                if (!Schema::hasColumn('consultations', 'ai_summary')) {
                    $table->longText('ai_summary')->nullable()->after('transcript');
                }
                if (!Schema::hasColumn('consultations', 'ai_summary_payload')) {
                    $table->json('ai_summary_payload')->nullable()->after('ai_summary');
                }
                if (!Schema::hasColumn('consultations', 'ai_summary_generated_at')) {
                    $table->timestamp('ai_summary_generated_at')->nullable()->after('ai_summary_payload');
                }
                if (!Schema::hasColumn('consultations', 'prescription_path')) {
                    $table->string('prescription_path', 500)->nullable()->after('ai_summary_generated_at');
                }
                if (!Schema::hasColumn('consultations', 'prescription_format')) {
                    $table->string('prescription_format', 20)->nullable()->after('prescription_path');
                }
                if (!Schema::hasColumn('consultations', 'prescription_medicines')) {
                    $table->json('prescription_medicines')->nullable()->after('prescription_format');
                }
            });
        }

        if (Schema::hasTable('telemedicine_session_notes')) {
            Schema::table('telemedicine_session_notes', function (Blueprint $table) {
                if (!Schema::hasColumn('telemedicine_session_notes', 'transcript')) {
                    $table->longText('transcript')->nullable()->after('plan');
                }
                if (!Schema::hasColumn('telemedicine_session_notes', 'ai_summary')) {
                    $table->longText('ai_summary')->nullable()->after('transcript');
                }
                if (!Schema::hasColumn('telemedicine_session_notes', 'ai_summary_payload')) {
                    $table->json('ai_summary_payload')->nullable()->after('ai_summary');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('consultations')) {
            Schema::table('consultations', function (Blueprint $table) {
                $columns = array_values(array_filter([
                    Schema::hasColumn('consultations', 'prescription_medicines') ? 'prescription_medicines' : null,
                    Schema::hasColumn('consultations', 'prescription_format') ? 'prescription_format' : null,
                    Schema::hasColumn('consultations', 'prescription_path') ? 'prescription_path' : null,
                    Schema::hasColumn('consultations', 'ai_summary_generated_at') ? 'ai_summary_generated_at' : null,
                    Schema::hasColumn('consultations', 'ai_summary_payload') ? 'ai_summary_payload' : null,
                    Schema::hasColumn('consultations', 'ai_summary') ? 'ai_summary' : null,
                    Schema::hasColumn('consultations', 'transcript') ? 'transcript' : null,
                ]));

                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('telemedicine_session_notes')) {
            Schema::table('telemedicine_session_notes', function (Blueprint $table) {
                $columns = array_values(array_filter([
                    Schema::hasColumn('telemedicine_session_notes', 'ai_summary_payload') ? 'ai_summary_payload' : null,
                    Schema::hasColumn('telemedicine_session_notes', 'ai_summary') ? 'ai_summary' : null,
                    Schema::hasColumn('telemedicine_session_notes', 'transcript') ? 'transcript' : null,
                ]));

                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
