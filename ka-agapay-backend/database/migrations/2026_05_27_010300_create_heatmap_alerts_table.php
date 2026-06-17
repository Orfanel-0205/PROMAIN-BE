<?php
//database/migrations/2026_05_27_010300_create_heatmap_alerts_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heatmap_alerts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('barangay_id')
                ->constrained('barangays', 'barangay_id')
                ->cascadeOnDelete();

            $table->string('disease_type', 100);
            // The disease associated with this alert

            $table->string('alert_type', 30);
            // 'outbreak_spike', 'congestion_alert', 'high_risk_zone'

            $table->string('severity', 15)->default('moderate');
            // 'low', 'moderate', 'high', 'critical'

            $table->text('trigger_message');
            // Human-readable description of what triggered the alert

            $table->unsignedInteger('case_count')->default(0);
            // The case count that triggered the alert

            $table->decimal('baseline_average', 8, 2)->default(0.00);
            // The historical baseline average used for comparison

            $table->decimal('deviation_factor', 5, 2)->default(0.00);
            // How many times the baseline was exceeded (e.g., 2.5x)

            $table->boolean('is_resolved')->default(false);
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->timestamps();

            // Foreign key for resolver (user_id uses custom PK)
            $table->foreign('resolved_by')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['barangay_id', 'is_resolved'], 'ha_brgy_resolved_idx');
            $table->index(['disease_type', 'created_at'], 'ha_disease_created_idx');
            $table->index(['severity', 'is_resolved'], 'ha_severity_resolved_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heatmap_alerts');
    }
};
