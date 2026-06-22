<?php
// database/migrations/2026_06_23_000004_create_service_feedback_table.php
//
// PHASE 5: Basic service feedback. Residents rate/comment after a completed
// consultation or other RHU service; admins can respond. RHU-scoped.
//
// FK columns are kept as plain nullable unsignedBigInteger (no DB-level
// constraints) so feedback never blocks if a related record is later removed,
// and so this migration is safe across the varying schema versions.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_feedback')) {
            return;
        }

        Schema::create('service_feedback', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('rhu_id')->nullable();

            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->unsignedBigInteger('consultation_id')->nullable();
            $table->unsignedBigInteger('queue_ticket_id')->nullable();
            $table->unsignedBigInteger('prescription_id')->nullable();
            $table->unsignedBigInteger('laboratory_result_id')->nullable();

            // onsite_consultation | online_consultation | queue_service |
            // laboratory | prescription | general_rhu_service
            $table->string('service_type', 50);

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();

            $table->text('admin_response')->nullable();
            $table->unsignedBigInteger('responded_by')->nullable();
            $table->timestamp('responded_at')->nullable();

            // submitted | reviewed | responded | archived
            $table->string('status', 20)->default('submitted');

            $table->timestamps();

            $table->index('user_id');
            $table->index('rhu_id');
            $table->index('consultation_id');
            $table->index('appointment_id');
            $table->index(['rhu_id', 'status']);
            $table->index(['service_type', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_feedback');
    }
};
