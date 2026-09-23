<?php
// database/migrations/2026_09_24_000000_create_municipalities_table.php
//
// Gives barangays and facilities a municipality, so a second town is possible
// at all.
//
// WHY (2026-09-24)
// ---------------
// `barangays.name` was UNIQUE across the whole table and there was no
// municipality column anywhere in the schema. Half the towns in Pangasinan
// have a Poblacion, a San Julian and a Bolaoen, so a second municipality's
// barangays could not be inserted — the database rejected them. The system was
// not multi-town with facilities; it was single-town with multiple facilities,
// and nothing said so.
//
// This is deliberately only the DIMENSION. It adds the column, backfills every
// existing row to Malasiqui, and swaps the unique constraint for a per-town
// one. It does NOT add scoping: nothing yet filters by municipality, and a
// second town must not be onboarded until that exists, or one LGU's staff
// would be able to read another's patients.
//
// Doing this now is cheap and doing it later is not. Every table added while
// the schema assumes one town is another table to revisit.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HOME_CODE = 'MALASIQUI';

    public function up(): void
    {
        if (!Schema::hasTable('municipalities')) {
            Schema::create('municipalities', function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique()->comment('Short key, e.g. MALASIQUI');
                $table->string('name', 120);
                $table->string('province', 120)->default('Pangasinan');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        $homeId = $this->ensureHomeMunicipality();

        $this->addColumn('barangays', $homeId);
        $this->addColumn('rhus', $homeId);

        $this->replaceBarangayNameUnique();
    }

    /**
     * Deliberately does not drop anything.
     *
     * Rolling back would mean restoring a global unique constraint on
     * barangay names, which fails outright the moment a second town exists —
     * turning a rollback into an outage. The columns are harmless if unused.
     */
    public function down(): void
    {
        // Intentionally empty.
    }

    private function ensureHomeMunicipality(): int
    {
        $existing = DB::table('municipalities')->where('code', self::HOME_CODE)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $id = DB::table('municipalities')->insertGetId([
            'code' => self::HOME_CODE,
            'name' => 'Malasiqui',
            'province' => 'Pangasinan',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $id;
    }

    /**
     * Add municipality_id and backfill it.
     *
     * Nullable on purpose. A NOT NULL column would have to be added, filled and
     * altered in three steps, and the middle one is a window where an insert
     * from the running application fails. Nullable plus a backfill plus an
     * application default is the same outcome without the window.
     */
    private function addColumn(string $table, int $homeId): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if (!Schema::hasColumn($table, 'municipality_id')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('municipality_id')->nullable()->index();
            });
        }

        DB::table($table)->whereNull('municipality_id')->update(['municipality_id' => $homeId]);
    }

    /**
     * barangays.name UNIQUE becomes (municipality_id, name) UNIQUE.
     *
     * The constraint name is read from the catalogue rather than assumed:
     * this table predates the current naming convention and not every
     * installation created it the same way. On PostgreSQL a failed statement
     * aborts the whole migration transaction, so nothing is dropped blind.
     */
    private function replaceBarangayNameUnique(): void
    {
        if (!Schema::hasTable('barangays') || !Schema::hasColumn('barangays', 'municipality_id')) {
            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $constraints = DB::select(
            "SELECT con.conname AS name
               FROM pg_constraint con
               JOIN pg_class rel ON rel.oid = con.conrelid
               JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
              WHERE rel.relname = 'barangays'
                AND nsp.nspname = current_schema()
                AND con.contype = 'u'
                AND (SELECT COUNT(*) FROM unnest(con.conkey)) = 1
                AND EXISTS (
                      SELECT 1 FROM pg_attribute att
                       WHERE att.attrelid = rel.oid
                         AND att.attnum = con.conkey[1]
                         AND att.attname = 'name'
                )"
        );

        foreach ($constraints as $constraint) {
            DB::statement(sprintf(
                'ALTER TABLE barangays DROP CONSTRAINT %s',
                '"' . str_replace('"', '""', $constraint->name) . '"'
            ));
        }

        $already = DB::selectOne(
            "SELECT 1 AS present
               FROM pg_constraint con
               JOIN pg_class rel ON rel.oid = con.conrelid
              WHERE rel.relname = 'barangays'
                AND con.conname = 'barangays_municipality_id_name_unique'"
        );

        if ($already === null) {
            DB::statement(
                'ALTER TABLE barangays
                   ADD CONSTRAINT barangays_municipality_id_name_unique
                   UNIQUE (municipality_id, name)'
            );
        }
    }
};
