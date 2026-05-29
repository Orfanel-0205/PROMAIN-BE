<?php
// database/migrations/YYYY_MM_DD_make_ocr_file_path_nullable.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_results', function (Blueprint $table) {
            $table->string('file_path', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ocr_results', function (Blueprint $table) {
            $table->string('file_path', 500)->nullable(false)->change();
        });
    }
};