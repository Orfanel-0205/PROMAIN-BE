<?php
//database/migrations/2026_04_26_172823_create_ai_triage_scores_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_triage_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')
                ->constrained('ai_requests')
                ->cascadeOnDelete();
            $table->foreignId('telemedicine_request_id')
                ->nullable()
                ->constrained('telemedicine_requests')
                ->nullOnDelete();
            $table->foreignId('queue_ticket_id')
                ->nullable()
                ->constrained('queue_tickets')
                ->nullOnDelete();

            $table->unsignedTinyInteger('ai_score');
            // 0–100, higher = more urgent

            $table->string('recommended_urgency', 20);
            // routine | urgent | emergency

            $table->jsonb('contributing_factors')->nullable();
            // explainability — what drove the score

            $table->decimal('confidence', 5, 4)->nullable();
            // 0.0000 to 1.0000

            $table->boolean('doctor_overrode')->default(false);
            $table->string('override_reason', 500)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['telemedicine_request_id', 'created_at']);
            $table->index(['queue_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_triage_scores');
    }
};
