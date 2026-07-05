<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds a sha1 image_hash to ocr_results so the IdDocumentValidator can reject the
// SAME ID image being reused across different accounts. Nullable + indexed;
// existing rows stay NULL and are ignored by the duplicate check.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ocr_results')) {
            return;
        }

        Schema::table('ocr_results', function (Blueprint $table) {
            if (!Schema::hasColumn('ocr_results', 'image_hash')) {
                $table->string('image_hash', 64)->nullable()->index()->after('file_path');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ocr_results') || !Schema::hasColumn('ocr_results', 'image_hash')) {
            return;
        }

        Schema::table('ocr_results', function (Blueprint $table) {
            $table->dropColumn('image_hash');
        });
    }
};
