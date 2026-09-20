<?php
// app/Services/Ai/AssistantTools.php
//
// What the assistant is allowed to look up in Ka-Agapay's own data.
//
// Until now it could only open screens: asked "how many are waiting?" it had
// to admit it could not see. These tools let the model answer from the real
// database, which is the difference between a menu of canned replies and an
// assistant that can be asked anything about today's work.
//
// Four rules hold, and they are the reason this file exists rather than the
// model being handed a database connection:
//
//   1. READ ONLY. Nothing here writes, updates or deletes. The worst a wrong
//      answer can do is mislead, never change a record.
//   2. AGGREGATES, NOT PEOPLE. Counts, totals and stock levels only — no
//      names, no diagnoses, no contact numbers. Chat messages are stored, so
//      anything returned here would end up in the chat log; patient data must
//      not. Looking a person up stays a navigation action that opens the
//      registry behind the staff member's own permissions.
//   3. THE CALLER'S OWN RHU. Every query is scoped with Rhu::filterRhuId, so
//      RHU 1 staff cannot ask about RHU 2. Only global-scope accounts
//      (super admin, MHO) see across facilities.
//   4. STAFF ONLY. Residents never reach these; their assistant answers from
//      their own records through the mobile app instead.

namespace App\Services\Ai;

use App\Models\User;
use App\Support\Rhu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssistantTools
{
    /**
     * Tool definitions in Gemini's functionDeclarations shape.
     *
     * @return array<int, array<string, mixed>>
     */
    public function declarations(): array
    {
        $rhuProperty = [
            'rhu_id' => [
                'type' => 'integer',
                'description' => 'Facility id to ask about. Omit for the staff member\'s own RHU.',
            ],
        ];

        return [
            [
                'name' => 'queue_status',
                'description' => 'How many patients are waiting, being called, in service and completed in today\'s queue.',
                'parameters' => ['type' => 'object', 'properties' => $rhuProperty],
            ],
            [
                'name' => 'appointment_counts',
                'description' => 'Appointment counts by status. Use scope "today", "upcoming" or "all".',
                'parameters' => [
                    'type' => 'object',
                    'properties' => $rhuProperty + [
                        'scope' => [
                            'type' => 'string',
                            'enum' => ['today', 'upcoming', 'all'],
                            'description' => 'Which appointments to count. Defaults to today.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'low_stock_medicines',
                'description' => 'Medicines and supplies at or below their minimum stock level, worst first.',
                'parameters' => ['type' => 'object', 'properties' => $rhuProperty],
            ],
            [
                'name' => 'prescription_counts',
                'description' => 'Prescription counts by status: active, partially dispensed, dispensed, voided.',
                'parameters' => ['type' => 'object', 'properties' => $rhuProperty],
            ],
            [
                'name' => 'followup_counts',
                'description' => 'Health follow-up counts: overdue, due today, upcoming, completed.',
                'parameters' => ['type' => 'object', 'properties' => $rhuProperty],
            ],
            [
                'name' => 'rhu_facilities',
                'description' => 'The Rural Health Units this system serves, and how many barangays each one covers.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ];
    }

    public function has(string $name): bool
    {
        foreach ($this->declarations() as $declaration) {
            if ($declaration['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run one tool for one staff member.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function run(string $name, array $arguments, User $user): array
    {
        $requested = isset($arguments['rhu_id']) ? (int) $arguments['rhu_id'] : null;

        // null here means "every facility", which only global-scope accounts get.
        $rhuId = Rhu::filterRhuId($user, $requested);
        $scopeLabel = $rhuId === null ? 'all RHUs' : (Rhu::rhuLabel($rhuId) ?? "RHU {$rhuId}");

        $result = match ($name) {
            'queue_status' => $this->queueStatus($rhuId),
            'appointment_counts' => $this->appointmentCounts($rhuId, (string) ($arguments['scope'] ?? 'today')),
            'low_stock_medicines' => $this->lowStockMedicines($rhuId),
            'prescription_counts' => $this->prescriptionCounts($rhuId),
            'followup_counts' => $this->followupCounts($rhuId),
            'rhu_facilities' => ['facilities' => Rhu::all()],
            default => ['error' => 'Unknown tool.'],
        };

        return $result + ['scope' => $scopeLabel, 'as_of' => now()->format('Y-m-d H:i')];
    }

    // ── the lookups ──────────────────────────────────────────────────────────

    private function queueStatus(?int $rhuId): array
    {
        if (!Schema::hasTable('queue_tickets')) {
            return ['error' => 'The queue is not set up on this installation.'];
        }

        $rows = $this->scoped(DB::table('queue_tickets'), $rhuId)
            ->whereDate('issued_at', today())
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as total'))
            ->pluck('total', 'status');

        return [
            'waiting' => (int) ($rows['waiting'] ?? 0),
            'being_called' => (int) ($rows['called'] ?? 0),
            'in_service' => (int) ($rows['in_service'] ?? 0),
            'completed_today' => (int) ($rows['completed'] ?? 0),
            'no_show' => (int) ($rows['no_show'] ?? 0),
            'total_issued_today' => (int) $rows->sum(),
        ];
    }

    private function appointmentCounts(?int $rhuId, string $scope): array
    {
        if (!Schema::hasTable('appointments')) {
            return ['error' => 'Appointments are not set up on this installation.'];
        }

        $query = $this->scoped(DB::table('appointments'), $rhuId);

        match ($scope) {
            'upcoming' => $query->whereDate('appointment_date', '>', today()),
            'all' => null,
            default => $query->whereDate('appointment_date', today()),
        };

        $rows = $query->groupBy('status')
            ->select('status', DB::raw('count(*) as total'))
            ->pluck('total', 'status');

        return [
            'scope_of_dates' => $scope === 'all' ? 'every date' : ($scope === 'upcoming' ? 'after today' : 'today'),
            'pending' => (int) ($rows['pending'] ?? 0),
            'confirmed' => (int) ($rows['confirmed'] ?? 0),
            'completed' => (int) ($rows['completed'] ?? 0),
            'cancelled' => (int) ($rows['cancelled'] ?? 0),
            'total' => (int) $rows->sum(),
        ];
    }

    private function lowStockMedicines(?int $rhuId): array
    {
        if (!Schema::hasTable('inventory_items')) {
            return ['error' => 'Inventory is not set up on this installation.'];
        }

        $items = $this->scoped(DB::table('inventory_items'), $rhuId)
            ->where('is_active', true)
            ->whereColumn('current_stock', '<=', 'minimum_stock_level')
            ->orderBy('current_stock')
            ->limit(10)
            ->get(['name', 'current_stock', 'minimum_stock_level', 'unit_of_measure']);

        return [
            'count' => $items->count(),
            'items' => $items->map(fn ($item) => [
                'name' => $item->name,
                'stock_left' => (int) $item->current_stock,
                'minimum' => (int) $item->minimum_stock_level,
                'unit' => $item->unit_of_measure,
            ])->all(),
        ];
    }

    private function prescriptionCounts(?int $rhuId): array
    {
        if (!Schema::hasTable('prescriptions')) {
            return ['error' => 'Prescriptions are not set up on this installation.'];
        }

        $rows = $this->scoped(DB::table('prescriptions'), $rhuId)
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as total'))
            ->pluck('total', 'status');

        return [
            'active' => (int) ($rows['active'] ?? 0),
            'partially_dispensed' => (int) ($rows['partially_dispensed'] ?? 0),
            'dispensed' => (int) ($rows['dispensed'] ?? 0),
            'voided' => (int) ($rows['voided'] ?? 0),
            'total' => (int) $rows->sum(),
        ];
    }

    private function followupCounts(?int $rhuId): array
    {
        if (!Schema::hasTable('follow_up_reminders')) {
            return ['error' => 'Follow-ups are not set up on this installation.'];
        }

        $base = fn () => $this->scoped(DB::table('follow_up_reminders'), $rhuId);

        return [
            'overdue' => (clone $base())
                ->whereIn('status', ['pending', 'scheduled'])
                ->whereDate('follow_up_date', '<', today())
                ->count(),
            'due_today' => (clone $base())
                ->whereIn('status', ['pending', 'scheduled'])
                ->whereDate('follow_up_date', today())
                ->count(),
            'upcoming' => (clone $base())
                ->whereIn('status', ['pending', 'scheduled'])
                ->whereDate('follow_up_date', '>', today())
                ->count(),
            'completed' => (clone $base())->where('status', 'completed')->count(),
        ];
    }

    /** Lock a query to one facility, unless the caller may see every facility. */
    private function scoped($query, ?int $rhuId)
    {
        return $rhuId === null ? $query : $query->where('rhu_id', $rhuId);
    }
}
