<?php
// database/migrations/2026_04_26_172823_create_ai_requests_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('triggered_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->string('request_type', 100);
            // triage_score | symptom_analysis | demand_forecast | soap_draft

            $table->string('model_used', 100)
                ->nullable()
                ->default('rule_engine_v1');

            $table->jsonb('input_payload');
            $table->jsonb('output_payload')->nullable();
            $table->integer('processing_time_ms')->nullable();

            $table->string('status', 30)->default('pending');
            // pending | processing | completed | failed

            $table->text('error_message')->nullable();

            // What triggered this AI request
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->boolean('was_applied')
                ->default(false)
                ->comment('Was this AI output acted on?');

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->index(['request_type', 'status', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
