<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barangays', function (Blueprint $table) {
            if (!Schema::hasColumn('barangays', 'latitude')) {
                $table->decimal('latitude', 10, 8)->nullable()->after('name');
            }

            if (!Schema::hasColumn('barangays', 'longitude')) {
                $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            }

            if (!Schema::hasColumn('barangays', 'population')) {
                $table->unsignedInteger('population')->default(800)->after('longitude');
            }
        });
    }

    public function down(): void
    {
        $columns = [];

        if (Schema::hasColumn('barangays', 'latitude')) {
            $columns[] = 'latitude';
        }

        if (Schema::hasColumn('barangays', 'longitude')) {
            $columns[] = 'longitude';
        }

        if (Schema::hasColumn('barangays', 'population')) {
            $columns[] = 'population';
        }

        if (!empty($columns)) {
            Schema::table('barangays', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};