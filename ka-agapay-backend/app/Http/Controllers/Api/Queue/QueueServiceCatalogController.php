<?php
// app/Http/Controllers/Api/Queue/QueueServiceCatalogController.php

namespace App\Http\Controllers\Api\Queue;

use App\Http\Controllers\Controller;
use App\Support\QueueServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The services the RHU queues patients for.
 *
 * Reading is open to any signed-in user: every service picker in the dashboard
 * and the app is built from this. Writing is a super admin or MHO decision,
 * because adding a service changes what staff can queue and what ticket
 * numbers look like.
 */
class QueueServiceCatalogController extends Controller
{
    /**
     * Every service, retired ones included, so an admin screen can show what
     * is switched off. Staff-facing pickers use only the active ones, which
     * the payload marks.
     */
    public function index(Request $request): JsonResponse
    {
        if (!Schema::hasTable('rhu_services')) {
            // Before the migration runs, report the built-in list rather than
            // an empty one: an empty list would read as "this RHU offers no
            // services" on a screen that is meant to be reassuring.
            return response()->json([
                'data' => array_map(
                    fn (array $service) => [
                        'id' => null,
                        'code' => $service['code'],
                        'name' => $service['name'],
                        'helper' => $service['helper'],
                        'ticket_prefix' => $service['prefix'],
                        'is_active' => true,
                        'sort_order' => 0,
                        'in_use' => false,
                        'editable' => false,
                    ],
                    QueueServices::active()
                ),
                'meta' => ['managed' => false],
            ]);
        }

        $rows = DB::table('rhu_services')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $counts = $this->ticketCounts();

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'helper' => (string) ($row->helper ?? ''),
                'ticket_prefix' => (string) $row->ticket_prefix,
                'is_active' => (bool) $row->is_active,
                'sort_order' => (int) $row->sort_order,

                // How many tickets have ever used it. The admin screen uses
                // this to explain why a service can be switched off but not
                // deleted.
                'in_use' => ($counts[$row->code] ?? 0) > 0,
                'ticket_count' => $counts[$row->code] ?? 0,
                'editable' => true,
            ])->all(),
            'meta' => ['managed' => true],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureTable();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'helper' => ['nullable', 'string', 'max:255'],
            'ticket_prefix' => ['required', 'string', 'max:8', 'regex:/^[A-Za-z0-9]+$/'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'ticket_prefix.regex' => 'The ticket prefix may only contain letters and numbers.',
        ]);

        $code = $this->codeFromName($validated['name']);

        if (DB::table('rhu_services')->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A service with a name like this already exists. Give it a clearly different name.'],
            ]);
        }

        $id = DB::table('rhu_services')->insertGetId([
            'code' => $code,
            'name' => trim($validated['name']),
            'helper' => trim((string) ($validated['helper'] ?? '')) ?: null,
            'ticket_prefix' => strtoupper($validated['ticket_prefix']),
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => ((int) DB::table('rhu_services')->max('sort_order')) + 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        QueueServices::forget();

        return response()->json([
            'message' => 'Service added.',
            'data' => ['id' => $id, 'code' => $code],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureTable();

        $service = DB::table('rhu_services')->where('id', $id)->first();

        if (!$service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'helper' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ticket_prefix' => ['sometimes', 'string', 'max:8', 'regex:/^[A-Za-z0-9]+$/'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:60000'],
        ]);

        /*
         * The code is never editable.
         *
         * It is what queue_tickets.service_type stores. Changing it would
         * orphan every ticket ever issued for this service: they would stop
         * matching, lose their name, and drop out of any report grouped by
         * service. The name can be reworded freely instead, which is what
         * anyone asking to "rename a service" actually wants.
         */
        $payload = ['updated_at' => now()];

        foreach (['name', 'helper', 'sort_order'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = is_string($validated[$field])
                    ? (trim($validated[$field]) ?: null)
                    : $validated[$field];
            }
        }

        if (array_key_exists('name', $payload) && !$payload['name']) {
            throw ValidationException::withMessages([
                'name' => ['A service needs a name.'],
            ]);
        }

        if (array_key_exists('ticket_prefix', $validated)) {
            $payload['ticket_prefix'] = strtoupper($validated['ticket_prefix']);
        }

        if (array_key_exists('is_active', $validated)) {
            $deactivating = !$validated['is_active'] && (bool) $service->is_active;

            // The queue has to be able to issue an ordinary consultation
            // ticket. Switching off the last active service, or the default
            // one, would leave staff with nothing to pick.
            if ($deactivating && $service->code === QueueServices::DEFAULT_CODE) {
                throw ValidationException::withMessages([
                    'is_active' => ['OPD Consultation is the fallback for every walk-in and cannot be switched off.'],
                ]);
            }

            if ($deactivating && $this->activeCount() <= 1) {
                throw ValidationException::withMessages([
                    'is_active' => ['At least one service has to stay switched on, or no ticket can be issued.'],
                ]);
            }

            $payload['is_active'] = $validated['is_active'];
        }

        DB::table('rhu_services')->where('id', $id)->update($payload);

        QueueServices::forget();

        return response()->json(['message' => 'Service updated.']);
    }

    /**
     * There is no delete.
     *
     * A service that has issued tickets cannot be removed without orphaning
     * them, and one that has not is harmless switched off. `is_active` covers
     * both, and keeps old tickets readable.
     */
    private function ensureTable(): void
    {
        if (Schema::hasTable('rhu_services')) {
            return;
        }

        throw ValidationException::withMessages([
            'service' => ['The service catalogue has not been migrated on this server yet.'],
        ]);
    }

    private function activeCount(): int
    {
        return (int) DB::table('rhu_services')->where('is_active', true)->count();
    }

    /** @return array<string, int> */
    private function ticketCounts(): array
    {
        if (!Schema::hasTable('queue_tickets')) {
            return [];
        }

        return DB::table('queue_tickets')
            ->select('service_type', DB::raw('COUNT(*) AS total'))
            ->groupBy('service_type')
            ->pluck('total', 'service_type')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * A stable machine code from the name the admin typed.
     *
     * Generated once and then frozen, because tickets store it. "Animal Bite
     * Treatment" becomes animal_bite_treatment.
     */
    private function codeFromName(string $name): string
    {
        $code = Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        return $code !== '' ? Str::limit($code, 60, '') : 'service_' . now()->format('ymdHis');
    }
}
