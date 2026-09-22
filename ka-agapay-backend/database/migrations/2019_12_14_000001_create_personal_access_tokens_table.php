<?php
// database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php
//
// The table Sanctum keeps API tokens in — every logged-in session, staff and
// resident alike.
//
// WHY THIS FILE IS HERE (2026-09-22)
// ----------------------------------
// Sanctum 3 loaded this migration from inside the package, so the application
// never had a copy of its own. Sanctum 4 stopped doing that: from Laravel 11
// onwards a package publishes its migrations and the application owns them.
// Upgrading without publishing it left the table undefined, so every login and
// registration returned a 500 — which is precisely what the test suite caught
// before any of it reached the server.
//
// Guarded, because production already has this table: the package copy created
// it back when Sanctum loaded it itself. The filename is unchanged, so an
// existing deployment has already recorded this migration as run and will skip
// it. The guard means it stays harmless even somewhere that record is missing.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Deliberately not dropped. Rolling this back on a live system would
        // sign out every staff member and resident at once, and the table is
        // older than this file is.
    }
};
