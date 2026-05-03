<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('telemedicine_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('telemedicine_sessions', 'room_id')) {
                $table->string('room_id', 100)->unique()->nullable()->after('status');
            }
            if (!Schema::hasColumn('telemedicine_sessions', 'room_token')) {
                $table->string('room_token', 255)->nullable()->after('room_id');
            }
            if (!Schema::hasColumn('telemedicine_sessions', 'ice_servers')) {
                $table->jsonb('ice_servers')->nullable()->after('room_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('telemedicine_sessions', function (Blueprint $table) {
            $table->dropColumn(['room_id', 'room_token', 'ice_servers']);
        });
    }

};
