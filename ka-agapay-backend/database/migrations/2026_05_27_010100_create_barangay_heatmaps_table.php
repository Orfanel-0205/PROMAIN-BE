<?php
//2026_05_27_010100_create_barangay_heatmaps_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barangay_heatmaps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('barangay_id')
                ->constrained('barangays', 'barangay_id')
                ->cascadeOnDelete();

            $table->string('disease_type', 100);
            // e.g., 'Dengue', 'Influenza', 'Gastroenteritis', 'Respiratory'

            $table->unsignedInteger('active_cases')->default(0);
            // Total consultation-confirmed cases for this disease in the log period

            $table->unsignedInteger('queue_density')->default(0);
            // Number of active waiting queue tickets originating from this barangay

            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            // Copied from barangays table for GIS-ready output

            $table->decimal('heatmap_intensity', 5, 2)->default(0.00);
            // Normalised intensity value (0.00 – 10.00) combining incidence rate + queue density

            $table->string('risk_level', 10)->default('low');
            // Enum-like: 'low', 'moderate', 'high', 'critical'

            $table->string('top_case_type', 100)->nullable();
            // The most frequently diagnosed condition in this barangay for the period

            $table->date('log_date')->index();
            // Daily aggregation anchor

            $table->timestamps();

            // Prevent duplicate daily rows per barangay per disease
            $table->unique(['barangay_id', 'disease_type', 'log_date'], 'bh_brgy_disease_date_unique');

            // Fast lookups for dashboard queries
            $table->index(['disease_type', 'log_date'], 'bh_disease_date_idx');
            $table->index(['risk_level', 'log_date'], 'bh_risk_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barangay_heatmaps');
    }
};
