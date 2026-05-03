<?php

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
        Schema::create('verification_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->string('id_photo_path', 500);
            $table->string('selfie_path', 500);
            $table->string('residency_path', 500)->nullable();
            $table->string('id_type', 50);
            $table->string('submission_ip', 45)->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('ocr_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_doc_id')->constrained('verification_documents')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->string('extracted_name', 200)->nullable();
            $table->string('extracted_birthdate', 50)->nullable();
            $table->text('extracted_address')->nullable();
            $table->string('extracted_id_number', 100)->nullable();
            $table->jsonb('raw_ocr_response')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->decimal('name_match_score', 5, 4)->nullable();
            $table->decimal('date_match_score', 5, 4)->nullable();
            $table->decimal('overall_match', 5, 4)->nullable();
            $table->string('ocr_status', 30)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('registration_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->foreignId('ocr_result_id')->nullable()->constrained('ocr_results')->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->text('review_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_approvals');
        Schema::dropIfExists('ocr_results');
        Schema::dropIfExists('verification_documents');
    }

};
