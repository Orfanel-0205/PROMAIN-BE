<?php
//2026_05_26_183130_add_coordinates_to_barangays_table.php
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
                $table->unsignedInteger('population')->default(0)->after('longitude');
            }
        });
    }

    public function down(): void
    {
        Schema::table('barangays', function (Blueprint $table) {
            $columns = array_filter(
                ['latitude', 'longitude', 'population'],
                fn($col) => Schema::hasColumn('barangays', $col)
            );
            if (!empty($columns)) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};