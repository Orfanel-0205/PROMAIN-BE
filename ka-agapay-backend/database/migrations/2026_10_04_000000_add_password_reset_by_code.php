<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Forgot password" by a code sent to the account holder's phone and email.
 *
 * Two changes:
 *
 *   verification_codes.user_id may be empty. A reset request for a number or
 *   email that has no account gets a stand-in challenge with no account behind
 *   it, stored and counted exactly like a real one. Every later step -- a
 *   wrong code, a resend, the wait between resends, expiry -- then answers the
 *   same way whether or not the account exists, so the reset page cannot be
 *   used to find out whether someone is a patient of the RHU. No code is ever
 *   sent for a stand-in, and nobody knows its code.
 *
 *   users.password_reset_at records the last reset done this way. Viewing API
 *   keys pauses for a day after one, as it does after a mobile number change:
 *   whoever took over a super admin's phone could otherwise reset the password
 *   and read every key straight away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        if (!Schema::hasColumn('users', 'password_reset_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('password_reset_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Stand-ins have no account and cannot survive the column becoming
        // required again; they are worthless after five minutes anyway.
        DB::table('verification_codes')->whereNull('user_id')->delete();

        Schema::table('verification_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        if (Schema::hasColumn('users', 'password_reset_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('password_reset_at');
            });
        }
    }
};
