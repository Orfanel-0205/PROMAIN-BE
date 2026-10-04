<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One mobile number may belong to one staff account AND one resident account.
 *
 * RHU staff are residents too. A nurse who is also a patient has a staff
 * account for the admin website and a resident account for the mobile app,
 * each with its own password and records -- and only one phone. Until now a
 * number could be on one active account in total, so the second account had
 * to carry a number that was not really theirs, and every code for it went
 * to a stranger or nowhere.
 *
 * users.is_staff says which kind an account is: true for every role except
 * resident and patient (the same line the code already draws for "needs
 * approval"). The User model keeps it in step with the role on every save.
 * The unique index now covers (mobile_number, is_staff), so a number is still
 * unique within each kind. Each sign-in screen looks only at its own kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'is_staff')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_staff')->default(false);
            });
        }

        DB::statement(
            "UPDATE users SET is_staff = (role_id IN (SELECT role_id FROM user_roles WHERE LOWER(name) NOT IN ('resident', 'patient')))"
        );

        DB::statement('DROP INDEX IF EXISTS users_mobile_number_unique');
        DB::statement(
            'CREATE UNIQUE INDEX users_mobile_number_kind_unique ON users (mobile_number, is_staff) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        $shared = DB::table('users')
            ->whereNull('deleted_at')
            ->select('mobile_number')
            ->groupBy('mobile_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('mobile_number');

        if ($shared->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot go back to one account per number: ' . $shared->count()
                . ' number(s) are on both a staff and a resident account. Change one of each pair first.'
            );
        }

        DB::statement('DROP INDEX IF EXISTS users_mobile_number_kind_unique');
        DB::statement(
            'CREATE UNIQUE INDEX users_mobile_number_unique ON users (mobile_number) WHERE deleted_at IS NULL'
        );

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_staff');
        });
    }
};
