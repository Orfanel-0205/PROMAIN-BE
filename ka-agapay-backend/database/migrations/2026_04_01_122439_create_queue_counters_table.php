<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rhu_id')->constrained('barangays');
            $table->enum('service_type', [
                'opd_consultation',
                'prenatal_checkup',
                'immunization',
                'family_planning',
                'tb_dots',
                'laboratory',
                'dental',
                'emergency',
                'medicine_release',
                'bhw_assisted',
            ]);
            $table->date('queue_date');
            $table->unsignedSmallInteger('last_issued_number')->default(0);
            $table->unsignedSmallInteger('current_serving_number')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['rhu_id', 'service_type', 'queue_date']);
            $table->index(['queue_date', 'rhu_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_counters');
    }
};