<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signatures the MHO and doctors draw or upload for their documents.
 *
 * Every save is a new row and the old one is marked revoked, never
 * overwritten. A document shows the signature that was in force when it
 * was issued, so adding a signature does not make months of unsigned
 * prescriptions look signed, and replacing one does not change what an
 * already-issued prescription showed. The image itself lives on the private
 * disk (SensitiveFiles), never under a public URL.
 *
 * consultations.completed_by records who completed the record. attended_by
 * is often the nurse who opened the chart; the ITR is signed by the doctor
 * or MHO who completed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('path');
            $table->string('sha256', 64);
            $table->string('source', 16);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        if (Schema::hasTable('consultations') && !Schema::hasColumn('consultations', 'completed_by')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->unsignedBigInteger('completed_by')->nullable()->after('completed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_signatures');

        if (Schema::hasTable('consultations') && Schema::hasColumn('consultations', 'completed_by')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->dropColumn('completed_by');
            });
        }
    }
};
