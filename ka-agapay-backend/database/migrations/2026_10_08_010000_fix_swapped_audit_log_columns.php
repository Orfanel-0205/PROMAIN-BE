<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put module and action back in their own columns.
 *
 * AuditService::info/warning/critical take (module, action). Twenty calls --
 * RHU facilities, registration approvals, prescriptions, referrals,
 * telemedicine and queue tickets -- passed them the other way round, so those
 * rows were filed under module "rhu.updated" with action "rhu", and filtering
 * the audit log by module missed them (96 of 782 rows on production in
 * October 2026). The calls are fixed; this swaps the two fields back on the
 * rows already written.
 *
 * Only the two columns trade places: who, when, what changed and every other
 * field is untouched. A row qualifies when its module looks like an action
 * ("something.verb") and its action does not -- no correctly filed row looks
 * like that. Running it twice changes nothing the second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        // In one UPDATE both right-hand sides read the row's old values, so
        // this is a swap, not a copy.
        DB::table('audit_logs')
            ->where('module', 'like', '%.%')
            ->where('action', 'not like', '%.%')
            ->update([
                'module' => DB::raw('action'),
                'action' => DB::raw('module'),
            ]);
    }

    public function down(): void
    {
        // Not reversed: the swapped placement was the bug.
    }
};
