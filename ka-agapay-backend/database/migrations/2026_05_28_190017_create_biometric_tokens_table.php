<?php
// database/migrations/2026_05_29_000001_create_biometric_tokens_table.php
// Replaces the single biometric_token_hash column on users with a proper
// join table — one row per device, supporting up to MAX_DEVICES per user.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained('users', 'user_id')
                  ->cascadeOnDelete();

            // SHA-256 of the raw token — never stored raw server-side
            $table->string('token_hash', 64)->unique();

            // Non-auth device hint for display / audit
            $table->string('device_hint', 100)->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->boolean('revoked')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'revoked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_tokens');
    }
};