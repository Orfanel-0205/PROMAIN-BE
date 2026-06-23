<?php
// database/migrations/2026_06_23_000008_create_follow_up_reminders_table.php
//
// Staff-created follow-up reminders saved from the SOAP workflow. Distinct from
// service_feedback (patient-submitted). RHU-scoped, additive, idempotent.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('follow_up_reminders')) {
            return;
        }

        Schema::create('follow_up_reminders', function (Blueprint $table) {
            $table->id();

            // Links (kept as plain nullable IDs so a deleted source never blocks).
            $table->unsignedBigInteger('consultation_id')->nullable();
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('resident_profile_id')->nullable();
            $table->unsignedBigInteger('rhu_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->string('patient_name')->nullable();
            $table->string('mobile_number', 40)->nullable();

            $table->timestamp('follow_up_at')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->time('follow_up_time')->nullable();

            $table->text('reason')->nullable();
            $table->text('instructions')->nullable();

            // routine | watch | urgent
            $table->string('urgency', 20)->default('routine');

            // pending | scheduled | completed | missed | cancelled
            $table->string('status', 20)->default('pending');

            // SMS tracking
            $table->boolean('sms_enabled')->default(true);
            $table->string('sms_status', 20)->nullable();
            $table->timestamp('sms_sent_at')->nullable();
            $table->text('sms_error')->nullable();

            $table->timestamps();

            $table->index('consultation_id');
            $table->index('user_id');
            $table->index('rhu_id');
            $table->index(['rhu_id', 'status']);
            $table->index('follow_up_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_reminders');
    }
};
