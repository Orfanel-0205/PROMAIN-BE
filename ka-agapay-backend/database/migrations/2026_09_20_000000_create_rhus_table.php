<?php
// database/migrations/2026_09_20_000000_create_rhus_table.php
//
// Makes the RHUs real records instead of two ids fixed in PHP.
//
// WHY (2026-09-20)
// ---------------
// "RHU 1" and "RHU 2" existed only as App\Support\Rhu::IDS = [1, 2]. Worse,
// queue tickets, queue counters, telemedicine requests, inventory, appointments
// and staff assignments all declared rhu_id as a FOREIGN KEY TO BARANGAYS. The
// values 1 and 2 only worked because barangays 1 and 2 happen to exist, so
// adding RHU 3 would have meant pointing a facility at an unrelated barangay.
//
// This migration:
//   1. creates the rhus table and fills it with the two existing facilities,
//      keeping their ids so every stored rhu_id still means the same place;
//   2. drops the rhu_id -> barangays foreign keys, which is what blocked a
//      third facility. The columns and their values stay exactly as they are.
//
// No foreign key to rhus is added in their place: the columns differ in type
// across tables (tinyint, integer, bigint) from years of separate migrations,
// and rewriting six live columns is a bigger risk than it removes. Facility
// ids are validated in the application instead (App\Support\Rhu::ids()), and
// barangays.rhu_id still records which facility serves each barangay.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables whose rhu_id wrongly referenced barangays. */
    private const LEGACY_FOREIGN_KEYS = [
        'queue_tickets' => 'rhu_id',
        'queue_counters' => 'rhu_id',
        'telemedicine_requests' => 'rhu_id',
        'inventory_items' => 'rhu_id',
        'appointments' => 'rhu_id',
        'consultations' => 'rhu_id',
        'users' => 'assigned_rhu_id',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('rhus')) {
            Schema::create('rhus', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique()->comment('Short key, e.g. RHU1');
                $table->string('name', 150);
                $table->string('short_name', 40);
                $table->string('address', 255)->nullable();
                $table->string('contact_number', 40)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('is_active');
            });
        }

        // The two facilities that already exist, with the ids their records use.
        $existing = DB::table('rhus')->count();

        if ($existing === 0) {
            DB::table('rhus')->insert([
                [
                    'id' => 1,
                    'code' => 'RHU1',
                    'name' => 'RHU 1 Malasiqui',
                    'short_name' => 'RHU 1',
                    'address' => 'Poblacion, Malasiqui, Pangasinan',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => 2,
                    'code' => 'RHU2',
                    'name' => 'RHU 2 Malasiqui (Don Pedro)',
                    'short_name' => 'RHU 2',
                    'address' => 'Don Pedro, Malasiqui, Pangasinan',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            // Postgres keeps its own sequence: without this, the first facility
            // added by an admin would try to reuse id 1.
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT setval(pg_get_serial_sequence('rhus', 'id'), (SELECT MAX(id) FROM rhus))");
            }
        }

        // Free the facility columns from the barangay table.
        //
        // Each constraint is looked up before it is dropped rather than
        // dropped-and-caught: on PostgreSQL a failed statement aborts the
        // whole migration transaction, so "constraint does not exist" would
        // take down everything after it. Names are read from the catalogue
        // because not every installation used Laravel's default naming.
        foreach (self::LEGACY_FOREIGN_KEYS as $table => $column) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }

            foreach ($this->foreignKeyNames($table, $column) as $constraint) {
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP CONSTRAINT %s',
                    $this->quote($table),
                    $this->quote($constraint)
                ));
            }
        }
    }

    /**
     * Foreign key constraints on one column, by name.
     *
     * @return array<int, string>
     */
    private function foreignKeyNames(string $table, string $column): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            // SQLite has no named constraints to drop; MySQL installations of
            // this project do not exist. Nothing to do either way.
            return [];
        }

        return DB::table('information_schema.table_constraints as tc')
            ->join('information_schema.key_column_usage as kcu', function ($join) {
                $join->on('tc.constraint_name', '=', 'kcu.constraint_name')
                    ->on('tc.table_schema', '=', 'kcu.table_schema');
            })
            ->where('tc.constraint_type', 'FOREIGN KEY')
            ->where('tc.table_name', $table)
            ->where('kcu.column_name', $column)
            ->pluck('tc.constraint_name')
            ->map(fn ($name) => (string) $name)
            ->unique()
            ->values()
            ->all();
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function down(): void
    {
        // The foreign keys are deliberately not restored: they pointed at the
        // wrong table. Dropping the rhus table alone is reversible enough.
        Schema::dropIfExists('rhus');
    }
};
