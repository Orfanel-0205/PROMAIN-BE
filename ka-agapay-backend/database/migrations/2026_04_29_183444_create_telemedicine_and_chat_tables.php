<?php
// database/migrations/2026_04_29_183444_create_telemedicine_and_chat_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webrtc_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('telemedicine_sessions')->onDelete('cascade');
            $table->foreignId('sender_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->foreignId('receiver_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->string('signal_type', 30); // offer | answer | ice_candidate | hang_up | mute | unmute
            $table->jsonb('payload');
            $table->timestamps();
        });

        Schema::create('chat_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users', 'user_id')->onDelete('cascade');
            $table->string('session_token', 100)->nullable();
            $table->string('role', 20); // user | assistant
            $table->text('message');
            $table->string('intent', 50)->nullable(); // symptom_inquiry | faq | appointment | triage | escalation
            $table->string('language', 10)->default('en');
            $table->integer('tokens_used')->nullable();
            $table->integer('response_ms')->nullable();
            $table->boolean('was_escalated')->default(false);
            $table->foreignId('escalated_to')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_logs');
        Schema::dropIfExists('webrtc_signals');
    }

};
