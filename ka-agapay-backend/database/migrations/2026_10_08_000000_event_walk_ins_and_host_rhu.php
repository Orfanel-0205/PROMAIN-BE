<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-ins at events, and "Hosted by" instead of "RHU only".
 *
 * WALK-INS. People who come to an event without registering in the app are
 * recorded on the registrants page as walk-ins: an existing patient, a new
 * patient account, or -- on a crowded day, or for someone who wants no
 * account -- just a name and barangay. The last kind has no user, so
 * event_registrations.user_id may now be empty (the unique event/user pair
 * still holds for everyone with an account; Postgres lets the empty ones
 * repeat).
 *
 * HOSTED BY. Every RHU serves the whole of Malasiqui, so a post restricted to
 * one RHU's residents (visibility rhu1/rhu2) reached only the residents whose
 * barangay is "home" to it -- and on production every barangay's home was
 * RHU 2. Posts are now seen by every resident and targeted by barangay; the
 * RHU becomes the host, shown as "Hosted by RHU 1". Existing restricted posts
 * keep their RHU as host and become visible to all (production had none).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();

            if (!Schema::hasColumn('event_registrations', 'is_walk_in')) {
                $table->boolean('is_walk_in')->default(false);
            }

            if (!Schema::hasColumn('event_registrations', 'walk_in_name')) {
                $table->string('walk_in_name', 150)->nullable();
            }

            if (!Schema::hasColumn('event_registrations', 'walk_in_barangay_id')) {
                $table->unsignedBigInteger('walk_in_barangay_id')->nullable();
            }
        });

        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'host_rhu_id')) {
                $table->unsignedBigInteger('host_rhu_id')->nullable();
            }
        });

        DB::table('events')
            ->where('visibility', 'like', 'rhu%')
            ->orderBy('id')
            ->each(function ($event) {
                $rhuId = (int) substr((string) $event->visibility, 3);

                DB::table('events')->where('id', $event->id)->update([
                    'host_rhu_id' => $rhuId > 0 ? $rhuId : null,
                    'visibility' => 'public',
                ]);
            });
    }

    public function down(): void
    {
        // The data change is not reversed: restricting posts again would hide
        // them from residents.
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'host_rhu_id')) {
                $table->dropColumn('host_rhu_id');
            }
        });

        Schema::table('event_registrations', function (Blueprint $table) {
            foreach (['is_walk_in', 'walk_in_name', 'walk_in_barangay_id'] as $column) {
                if (Schema::hasColumn('event_registrations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
