
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
        /*
        |--------------------------------------------------------------------------
        | VERIFICATION DOCUMENTS
        |--------------------------------------------------------------------------
        */

        Schema::create('verification_documents', function (Blueprint $table) {

            $table->id();

            // User reference
            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->onDelete('cascade');

            // Uploaded ID image
            $table->string('id_photo_path', 500);

            // Uploaded selfie
            $table->string('selfie_path', 500);

            // Optional proof of residency
            $table->string('residency_path', 500)
                ->nullable();

            // Type of uploaded ID
            $table->string('id_type', 100);

            // User IP address
            $table->string('submission_ip', 45)
                ->nullable();

            // Submission timestamp
            $table->timestamp('submitted_at')
                ->useCurrent();

            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | OCR RESULTS
        |--------------------------------------------------------------------------
        */

        Schema::create('ocr_results', function (Blueprint $table) {

            $table->id();

            // Verification document reference
            $table->foreignId('verification_doc_id')
                ->nullable()
                ->constrained('verification_documents')
                ->nullOnDelete();

            // User reference
            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->onDelete('cascade');

            /*
            |--------------------------------------------------------------------------
            | FILE INFORMATION
            |--------------------------------------------------------------------------
            */

            // Type of uploaded ID
            $table->string('id_type', 100)
                ->nullable();

            // Stored file path
            $table->string('file_path', 500);

            /*
            |--------------------------------------------------------------------------
            | OCR TEXT
            |--------------------------------------------------------------------------
            */

            // Full OCR extracted text
            $table->longText('extracted_text')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | STRUCTURED EXTRACTION
            |--------------------------------------------------------------------------
            */

            $table->string('extracted_name', 200)
                ->nullable();

            $table->string('extracted_birthdate', 100)
                ->nullable();

            $table->text('extracted_address')
                ->nullable();

            $table->string('extracted_id_number', 100)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | RAW OCR RESPONSE
            |--------------------------------------------------------------------------
            */

            $table->json('raw_ocr_response')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | OCR SCORES
            |--------------------------------------------------------------------------
            */

            $table->decimal('confidence_score', 5, 2)
                ->nullable();

            $table->decimal('name_match_score', 5, 2)
                ->nullable();

            $table->decimal('date_match_score', 5, 2)
                ->nullable();

            $table->decimal('overall_match', 5, 2)
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | OCR STATUS
            |--------------------------------------------------------------------------
            */

            $table->enum('status', [
                'pending',
                'processing',
                'approved',
                'failed',
            ])->default('pending');

            /*
            |--------------------------------------------------------------------------
            | PROCESSING TIMESTAMP
            |--------------------------------------------------------------------------
            */

            $table->timestamp('processed_at')
                ->nullable();

            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | REGISTRATION APPROVALS
        |--------------------------------------------------------------------------
        */

        Schema::create('registration_approvals', function (Blueprint $table) {

            $table->id();

            // User being reviewed
            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->onDelete('cascade');

            // Admin reviewer
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            // OCR result reference
            $table->foreignId('ocr_result_id')
                ->nullable()
                ->constrained('ocr_results')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | APPROVAL STATUS
            |--------------------------------------------------------------------------
            */

            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
            ])->default('pending');

            // Admin notes
            $table->text('review_notes')
                ->nullable();

            // Rejection reason
            $table->text('rejection_reason')
                ->nullable();

            // Reviewed timestamp
            $table->timestamp('reviewed_at')
                ->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_approvals');

        Schema::dropIfExists('ocr_results');

        Schema::dropIfExists('verification_documents');
    }
};
