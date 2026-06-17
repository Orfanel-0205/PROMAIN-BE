<?php
//database/migrations/2026_05_27_010200_create_disease_clusters_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disease_clusters', function (Blueprint $table) {
            $table->id();

            $table->string('disease_type', 100);
            // The disease that defines this spatial cluster

            $table->unsignedInteger('case_count');
            // Total cases within the cluster boundary

            $table->unsignedInteger('barangay_count')->default(1);
            // Number of barangays involved in this cluster

            $table->decimal('center_latitude', 10, 8);
            $table->decimal('center_longitude', 11, 8);
            // Geographic centroid of the cluster

            $table->decimal('radius_km', 5, 2)->default(0.00);
            // Approximate radius of the cluster in kilometres

            $table->decimal('density_index', 5, 2)->default(0.00);
            // Cases per km² within the cluster area

            $table->jsonb('affected_barangays')->nullable();
            // Array of barangay names/IDs within the cluster

            $table->date('period_start');
            $table->date('period_end');
            // The date range over which the cluster was computed

            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['disease_type', 'detected_at'], 'dc_disease_detected_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disease_clusters');
    }
};
