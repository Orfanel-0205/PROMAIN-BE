<?php
// database/migrations/2026_09_23_000000_create_rhu_services_table.php
//
// Makes the queue's service list a set of records instead of ten strings
// repeated across eight files.
//
// WHY (2026-09-23)
// ---------------
// "OPD Consultation", "TB DOTS" and the rest were hardcoded in
// QueueService::SERVICE_CODES, QueueController::serviceTypes(),
// IssueQueueTicketRequest, QueueListRequest, QueueTicketResource,
// NotificationService, QueueService's label map, and again in the admin. The
// RHU could not add a service it had started offering, or retire one it had
// stopped, without a developer editing all of them — and missing one produced
// a service that could be queued but not validated, or issued a ticket with no
// prefix.
//
// The column this feeds is already a varchar in production: an earlier
// migration widened it from the original Postgres enum. So a new service is a
// row, not a schema change, which is the whole reason this can be an admin
// screen rather than a deployment.
//
// Nothing is dropped and no existing column changes. The ten current services
// are inserted with the codes and ticket prefixes they already use, so every
// ticket ever issued still resolves to the same service and keeps its number
// series.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The services as they are hardcoded today: code, name, the helper line
     * shown under it in the admin, and the ticket prefix its numbers carry.
     *
     * The prefixes come from QueueService::SERVICE_CODES and must not change:
     * they are printed on tickets patients are holding.
     */
    private const EXISTING = [
        ['opd_consultation', 'OPD Consultation', 'General check-up and common illness concerns', 'OPD'],
        ['prenatal_checkup', 'Prenatal Checkup', 'Pregnant patients and maternal care', 'PRE'],
        ['immunization', 'Immunization', 'Vaccination and child immunization', 'IMM'],
        ['family_planning', 'Family Planning', 'Family planning consultation and services', 'FP'],
        ['tb_dots', 'TB DOTS', 'Tuberculosis treatment and follow-up', 'TB'],
        ['laboratory', 'Laboratory', 'Lab request and specimen processing', 'LAB'],
        ['dental', 'Dental', 'Dental consultation and treatment', 'DEN'],
        ['emergency', 'Emergency', 'Urgent cases that need immediate attention', 'ER'],
        ['medicine_release', 'Medicine Release', 'Prescription claiming and medicine release', 'MED'],
        ['bhw_assisted', 'BHW Assisted', 'Barangay Health Worker endorsed patients', 'BHW'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('rhu_services')) {
            Schema::create('rhu_services', function (Blueprint $table) {
                $table->id();

                // What queue_tickets.service_type stores. Immutable once a
                // ticket has used it, which the application enforces.
                $table->string('code', 60)->unique();

                $table->string('name', 120);
                $table->string('helper', 255)->nullable()
                    ->comment('One line explaining who this service is for');

                // Prepended to ticket numbers, e.g. OPD-014.
                $table->string('ticket_prefix', 8);

                $table->boolean('is_active')->default(true)
                    ->comment('Retired services stay here so old tickets still resolve');

                $table->unsignedSmallInteger('sort_order')->default(0);

                $table->timestamps();

                $table->index(['is_active', 'sort_order']);
            });
        }

        if (DB::table('rhu_services')->count() > 0) {
            return;
        }

        $now = now();
        $rows = [];

        foreach (self::EXISTING as $index => [$code, $name, $helper, $prefix]) {
            $rows[] = [
                'code' => $code,
                'name' => $name,
                'helper' => $helper,
                'ticket_prefix' => $prefix,
                'is_active' => true,
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('rhu_services')->insert($rows);

        // Any service_type already on a ticket but not in that list — from a
        // hand-edited row, or a value that predates the current list. Added
        // inactive so it keeps resolving without being offered to staff.
        if (!Schema::hasTable('queue_tickets')) {
            return;
        }

        $known = array_column(self::EXISTING, 0);

        $orphans = DB::table('queue_tickets')
            ->select('service_type')
            ->distinct()
            ->whereNotNull('service_type')
            ->whereNotIn('service_type', $known)
            ->pluck('service_type')
            ->filter(fn ($value) => trim((string) $value) !== '')
            ->values();

        if ($orphans->isEmpty()) {
            return;
        }

        $extra = [];

        foreach ($orphans as $offset => $code) {
            $extra[] = [
                'code' => (string) $code,
                'name' => ucwords(str_replace('_', ' ', (string) $code)),
                'helper' => 'Recovered from existing queue tickets during migration.',
                'ticket_prefix' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $code) ?: 'SVC', 0, 3)),
                'is_active' => false,
                'sort_order' => 1000 + $offset,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('rhu_services')->insert($extra);
    }

    /**
     * Deliberately does not drop the table.
     *
     * Rolling this back would discard any service the RHU added since, and the
     * code falls back to the original ten when the table is absent — so there
     * is nothing to gain by dropping it and a catalogue to lose.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
