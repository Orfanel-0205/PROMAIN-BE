<?php
// database/migrations/2026_06_14_000001_upgrade_chat_sessions_for_separate_history.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_sessions')) {
            Schema::create('chat_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users', 'user_id')
                    ->nullOnDelete();
                $table->string('session_token', 100)->unique();
                $table->string('audience', 20)->default('resident'); // resident | staff
                $table->string('title', 160)->nullable();
                $table->string('language', 10)->default('en');
                $table->string('status', 20)->default('active'); // active | ended | deleted
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'audience', 'status', 'last_activity_at']);
            });
        } else {
            Schema::table('chat_sessions', function (Blueprint $table) {
                if (!Schema::hasColumn('chat_sessions', 'session_token')) {
                    $table->string('session_token', 100)->nullable()->unique()->after('user_id');
                }
                if (!Schema::hasColumn('chat_sessions', 'audience')) {
                    $table->string('audience', 20)->default('resident')->after('session_token');
                }
                if (!Schema::hasColumn('chat_sessions', 'title')) {
                    $table->string('title', 160)->nullable()->after('audience');
                }
                if (!Schema::hasColumn('chat_sessions', 'status')) {
                    $table->string('status', 20)->default('active')->after('language');
                }
                if (!Schema::hasColumn('chat_sessions', 'last_activity_at')) {
                    $table->timestamp('last_activity_at')->nullable()->after('status');
                }
                if (!Schema::hasColumn('chat_sessions', 'created_at')) {
                    $table->timestamps();
                }
            });
        }

        if (!Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('chat_session_id')
                    ->constrained('chat_sessions')
                    ->cascadeOnDelete();
                $table->string('role', 20); // user | assistant
                $table->text('message');
                $table->string('language', 10)->default('en');
                $table->string('intent', 60)->nullable();
                $table->string('suggested_action', 60)->nullable();
                $table->integer('tokens_used')->nullable();
                $table->integer('response_time_ms')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['chat_session_id', 'created_at']);
            });
        } else {
            Schema::table('chat_messages', function (Blueprint $table) {
                if (!Schema::hasColumn('chat_messages', 'intent')) {
                    $table->string('intent', 60)->nullable()->after('language');
                }
                if (!Schema::hasColumn('chat_messages', 'suggested_action')) {
                    $table->string('suggested_action', 60)->nullable()->after('intent');
                }
                if (!Schema::hasColumn('chat_messages', 'tokens_used')) {
                    $table->integer('tokens_used')->nullable()->after('suggested_action');
                }
                if (!Schema::hasColumn('chat_messages', 'response_time_ms')) {
                    $table->integer('response_time_ms')->nullable()->after('tokens_used');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_sessions');
    }
};