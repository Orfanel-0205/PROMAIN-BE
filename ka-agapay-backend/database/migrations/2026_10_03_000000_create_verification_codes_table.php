<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes sent by SMS to confirm it is really the account holder.
 *
 * Used in two places:
 *
 *   Signing in after a wrong password. A correct password on an account that
 *   has had a wrong one since its last sign-in is not enough on its own; the
 *   code proves the person also has the account holder's phone. Because the
 *   code is sent only once the password is right, someone guessing passwords
 *   can never make the system send a text.
 *
 *   Viewing an API key in Settings, which needs the super admin's password
 *   and a code every time.
 *
 * The code itself is never stored, only an HMAC of it bound to the challenge,
 * so a copy of this table -- or a database backup -- cannot be used to sign
 * in. The challenge is a long random string the client holds between the two
 * steps; the code is the six digits the person reads off their phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();

            // Handed to the client after the password step and sent back with
            // the code. Random, single-use, and useless without the code.
            $table->string('challenge', 64)->unique();

            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();

            // admin_login | resident_login | reveal_key
            $table->string('purpose', 32);

            // HMAC-SHA256 of challenge|code with the app key. Never the code.
            $table->string('code_hash', 64);

            // What the code unlocks, e.g. {"integration":"gemini","field":"api_key"}.
            $table->json('context')->nullable();

            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('sends')->default(1);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('consumed_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'purpose', 'created_at']);
            $table->index('expires_at');
        });

        /*
         * When the account's mobile number last changed.
         *
         * A code proves the person has the account holder's phone only if the
         * phone is still the account holder's. Someone at an unlocked
         * computer could otherwise change the number to their own and then
         * receive the code. Viewing API keys is paused for a day after a
         * change, and the old number is told about it.
         */
        if (!Schema::hasColumn('users', 'mobile_changed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('mobile_changed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'mobile_changed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('mobile_changed_at');
            });
        }

        Schema::dropIfExists('verification_codes');
    }
};
