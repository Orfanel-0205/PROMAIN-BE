<?php
// database/migrations/2026_03_27_160734_create_events_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('image_url')->nullable();
            $table->string('banner_image')->nullable();

            $table->string('location')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->dateTime('event_date')->nullable();
            $table->dateTime('ends_at')->nullable();

            /**
             * Real CMS post type:
             * event        = scheduled activity
             * program      = health program
             * announcement = general advisory
             */
            $table->string('event_type', 30)->default('event');

            /**
             * Specific category:
             * Immunization, Medical Mission, TB DOTS, Maternal Care, etc.
             */
            $table->string('category', 100)->nullable();

            $table->string('barangay_target', 150)->default('all');
            $table->string('target_audience')->nullable();

            $table->json('tags')->nullable();

            $table->integer('max_slots')->nullable();
            $table->integer('slots_available')->nullable();

            $table->string('sms_summary', 160)->nullable();

            $table->string('priority', 20)->default('normal');
            $table->string('visibility', 30)->default('public');

            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'event_date']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};