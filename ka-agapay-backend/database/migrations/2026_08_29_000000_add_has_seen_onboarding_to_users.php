<?php
// database/migrations/2026_08_29_000000_add_has_seen_onboarding_to_users.php
//
// First-login "Getting Started" auto-open flag.
//
// Additive-only and guarded (hasTable / hasColumn), so re-running against live
// production data is safe and nothing existing is touched or dropped.
//
// WHY A NEW COLUMN INSTEAD OF REUSING last_login_at:
// AuthController stamps last_login_at DURING login and then returns
// $user->fresh() in the same response, so by the time the browser receives the
// user payload the value is already non-null -- even on a genuine first login.
// It therefore cannot distinguish "first ever" from "second", and is unusable
// as this signal. A dedicated flag is explicit and cannot be clobbered by the
// login bookkeeping.
//
// BACKFILL: existing accounts have, by definition, already logged in for the
// first time, so they are marked as having seen onboarding and will NOT get a
// surprise tutorial on their next sign-in. Accounts that exist but have never
// actually logged in (approved, never signed in: last_login_at IS NULL) keep
// the default false, so they still get the tour on their real first login.
// Note last_login_at is reliable HERE -- at migration time it reflects real
// history; it is only unusable as a per-request signal.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || Schema::hasColumn('users', 'has_seen_onboarding')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('has_seen_onboarding')->default(false);
        });

        if (Schema::hasColumn('users', 'last_login_at')) {
            DB::table('users')
                ->whereNotNull('last_login_at')
                ->update(['has_seen_onboarding' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'has_seen_onboarding')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('has_seen_onboarding');
            });
        }
    }
};
