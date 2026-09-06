<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real storage for the admin Settings page.
 *
 * Replaces localStorage. Until now Facility Information, Notifications & SMS
 * and Security Rules all wrote to a single browser key
 * ("ka_agapay_admin_settings_v2") and read it back, so "saved" meant "saved on
 * this laptop, in this browser, for this person". Nothing reached the server.
 *
 * WHY ONE KEY-VALUE TABLE RATHER THAN ONE TABLE PER SECTION
 * ---------------------------------------------------------
 * Three sections hold roughly a dozen short scalar fields between them, and
 * that set is expected to grow as more of the page becomes real. A table per
 * section means a migration for every new field and three models that differ
 * only in column names. A typed key-value table means new fields are a
 * validation-rule change and nothing else, while `type` keeps values from
 * degrading into strings on the way back out.
 *
 * The trade-off is that the database no longer enforces the shape of a
 * section. That is accepted deliberately: validation lives in the controller's
 * rules, which is where per-field messages have to live anyway for the UI to
 * be useful. The unique index below is what the database does enforce.
 *
 * WHY rhu_id IS NOT NULL WITH A 0 SENTINEL
 * ----------------------------------------
 * Facility Information is per-facility: RHU 1 and RHU 2 are different
 * buildings with different addresses and phone numbers, so they need separate
 * rows. SMS and Security are municipality-wide and shared.
 *
 * The obvious spelling of "shared" is rhu_id NULL, but PostgreSQL treats NULLs
 * as distinct in a unique index, so (group, NULL, key) would happily accept
 * unlimited duplicates -- exactly the silent-corruption failure this codebase
 * keeps getting bitten by. 0 is a real value, so the index actually holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();

            // 'facility' | 'notifications' | 'security'
            $table->string('group', 32);

            // 0 = applies to every RHU. Otherwise a facility id (Rhu::IDS).
            $table->unsignedInteger('rhu_id')->default(0);

            $table->string('key', 64);

            // Null is a real state: "this field has never been set", which the
            // UI must render as an honest empty rather than inventing a value.
            $table->text('value')->nullable();

            // 'string' | 'int' | 'bool' -- so an int comes back as an int.
            $table->string('type', 16)->default('string');

            // users' primary key is user_id, not id -- the second argument is
            // required or the constraint looks for a column that does not exist.
            $table->foreignId('updated_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();

            $table->timestamps();

            $table->unique(['group', 'rhu_id', 'key'], 'app_settings_group_rhu_key_unique');
            $table->index(['group', 'rhu_id'], 'app_settings_group_rhu_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
