<?php
// app/Http/Controllers/Api/InventoryController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Support\Rhu;
use App\Services\Audit\AuditService;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $service,
        private readonly AuditService $audit
    ) {}

    private function authorizeInventory(Request $request, bool $strict = false): void
    {
        $roles = $strict
            ? ['mho', 'super_admin']
            : ['mho', 'super_admin', 'staff_admin', 'admin', 'rhu_admin', 'staff', 'doctor', 'nurse', 'midwife'];

        abort_unless(
            $request->user()?->hasAnyRole($roles),
            403,
            'You are not allowed to manage inventory.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeInventory($request);

        $validated = $request->validate([
            'rhu_id'    => ['required', 'integer'],
            'category'  => ['nullable', 'in:' . implode(',', InventoryItem::CATEGORIES)],
            'low_stock' => ['nullable', 'boolean'],
            'expiring'  => ['nullable', 'boolean'],
            'search'    => ['nullable', 'string', 'max:100'],
            'per_page'  => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        // RHU isolation: non-global staff are hard-locked to their own facility
        // even if a different rhu_id is requested.
        $rhuId = Rhu::scopeRhuId($request->user(), (int) $validated['rhu_id']);

        $items = InventoryItem::with([
                'transactions' => fn ($q) => $q->latest('created_at')->limit(3),
            ])
            ->forRhu($rhuId)
            ->active()
            ->when($request->filled('category'), function ($q) use ($request) {
                $q->where('category', $request->category);
            })
            ->when($request->boolean('low_stock'), function ($q) {
                $q->lowStock();
            })
            ->when($request->boolean('expiring'), function ($q) {
                $q->expiringSoon(30);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim((string) $request->search);

                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'ilike', "%{$search}%")
                        ->orWhere('generic_name', 'ilike', "%{$search}%")
                        ->orWhere('item_code', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json($items);
    }

    public function searchMedicines(Request $request): JsonResponse
    {
        $this->authorizeInventory($request);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'rhu_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $queryText = trim((string) ($validated['q'] ?? ''));
        // RHU isolation: non-global staff are hard-locked to their own facility.
        $rhuId = Rhu::scopeRhuId(
            $request->user(),
            isset($validated['rhu_id']) ? (int) $validated['rhu_id'] : null
        );

        $items = InventoryItem::query()
            ->forRhu($rhuId)
            ->active()
            ->where('category', 'medicine')
            ->when($queryText !== '', function ($query) use ($queryText) {
                $query->where(function ($inner) use ($queryText) {
                    $inner->where('name', 'ilike', "%{$queryText}%")
                        ->orWhere('generic_name', 'ilike', "%{$queryText}%")
                        ->orWhere('item_code', 'ilike', "%{$queryText}%");
                });
            })
            ->when($queryText !== '', function ($query) use ($queryText) {
                $query->orderByRaw(
                    "CASE WHEN name ILIKE ? THEN 0 WHEN generic_name ILIKE ? THEN 1 ELSE 2 END",
                    [$queryText . '%', $queryText . '%']
                );
            })
            ->orderBy('name')
            ->limit((int) ($validated['limit'] ?? 10))
            ->get();

        return response()->json([
            'data' => $items->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'generic_name' => $item->generic_name,
                'brand_name' => null,
                'dosage_form' => $item->dosage_form,
                'strength' => null,
                'unit_of_measure' => $item->unit_of_measure,
                'stock' => $item->current_stock,
                'current_stock' => $item->current_stock,
                'item_code' => $item->item_code,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeInventory($request);

        $validated = $request->validate([
            'rhu_id'                  => ['required', 'integer'],
            'item_code'               => ['nullable', 'string', 'max:30'],
            'name'                    => ['required', 'string', 'max:200'],
            'generic_name'            => ['nullable', 'string', 'max:200'],
            'category'                => ['required', 'in:' . implode(',', InventoryItem::CATEGORIES)],
            'unit_of_measure'         => ['required', 'string', 'max:30'],
            'dosage_form'             => ['nullable', 'string', 'max:50'],
            'current_stock'           => ['required', 'integer', 'min:0'],
            'minimum_stock_level'     => ['required', 'integer', 'min:0'],
            'maximum_stock_level'     => ['nullable', 'integer', 'min:0'],
            'reorder_point'           => ['nullable', 'integer', 'min:0'],
            'expiration_date'         => ['nullable', 'date'],
            'is_controlled_substance' => ['sometimes', 'boolean'],
            'requires_prescription'   => ['sometimes', 'boolean'],
            'notes'                   => ['nullable', 'string'],
        ]);

        // If staff typed an item code, it must be globally unique (including
        // soft-deleted rows, because the DB unique index still holds them).
        $providedCode = isset($validated['item_code'])
            ? strtoupper(trim((string) $validated['item_code']))
            : null;

        unset($validated['item_code']);

        if ($providedCode !== null && $providedCode !== '') {
            $taken = InventoryItem::withTrashed()
                ->where('item_code', $providedCode)
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'item_code' => ['This item code is already in use. Leave it blank to auto-generate a unique code.'],
                ]);
            }
        } else {
            $providedCode = null;
        }

        try {
            $item = $this->createWithUniqueItemCode($validated, $providedCode);
        } catch (QueryException $e) {
            // 23505 = PostgreSQL unique_violation. Never leak SQL to the client.
            if ($this->isUniqueViolation($e)) {
                return response()->json([
                    'message' => 'Could not save the inventory item because the item code is already in use. Please try again.',
                    'errors'  => [
                        'item_code' => ['Item code collision. Please retry or enter a different code.'],
                    ],
                ], 422);
            }

            report($e);

            return response()->json([
                'message' => 'Unable to save the inventory item. Please try again or contact the system administrator.',
            ], 500);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to save the inventory item. Please try again or contact the system administrator.',
            ], 500);
        }

        return response()->json([
            'message' => 'Inventory item created.',
            'data' => $item->fresh(),
        ], 201);
    }

    /**
     * Create an inventory item with a guaranteed-unique item_code.
     *
     * Auto-generated codes are derived from the highest existing suffix ACROSS
     * ALL rows (including soft-deleted ones, which still occupy the unique
     * index). A short retry loop absorbs the rare race where two requests pick
     * the same next number at the same instant.
     */
    private function createWithUniqueItemCode(array $validated, ?string $providedCode): InventoryItem
    {
        if ($providedCode !== null) {
            return InventoryItem::create(array_merge($validated, [
                'item_code' => $providedCode,
                'is_active' => true,
            ]));
        }

        $year = now()->year;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $code = $this->nextItemCode($year);

            try {
                return InventoryItem::create(array_merge($validated, [
                    'item_code' => $code,
                    'is_active' => true,
                ]));
            } catch (QueryException $e) {
                if (!$this->isUniqueViolation($e) || $attempt >= 5) {
                    throw $e;
                }

                // Tiny jitter, then recompute the next code and retry.
                usleep(random_int(1500, 6000));
            }
        }

        // Unreachable (the loop returns or throws within 5 attempts), but PHP
        // control-flow analysis still needs a terminal statement here.
        throw new \RuntimeException('Could not allocate a unique inventory item code.');
    }

    /**
     * Next available MED-{year}-#### code, based on the global maximum suffix
     * (including soft-deleted rows) so a deleted/reused number is never picked.
     */
    private function nextItemCode(int $year): string
    {
        $prefix = 'MED-' . $year . '-';

        $highest = InventoryItem::withTrashed()
            ->where('item_code', 'like', $prefix . '%')
            ->pluck('item_code')
            ->map(function ($code) use ($prefix) {
                $suffix = substr((string) $code, strlen($prefix));
                return (int) preg_replace('/\D/', '', $suffix);
            })
            ->max();

        $next = ((int) $highest) + 1;

        // Defensive: skip any specific code still held by an existing/trashed row.
        while (
            InventoryItem::withTrashed()
                ->where('item_code', $prefix . sprintf('%04d', $next))
                ->exists()
        ) {
            $next++;
        }

        return $prefix . sprintf('%04d', $next);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        if ((string) $e->getCode() === '23505') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'item_code');
    }

    public function show(Request $request, InventoryItem $inventory): JsonResponse
    {
        $this->authorizeInventory($request);

        return response()->json([
            'data' => $inventory->load([
                'transactions' => fn ($q) => $q->latest('created_at')->limit(20),
            ]),
        ]);
    }

    public function update(Request $request, InventoryItem $inventory): JsonResponse
    {
        $this->authorizeInventory($request);

        $validated = $request->validate([
            'rhu_id'                  => ['sometimes', 'integer'],
            'name'                    => ['sometimes', 'string', 'max:200'],
            'generic_name'            => ['nullable', 'string', 'max:200'],
            'category'                => ['sometimes', 'in:' . implode(',', InventoryItem::CATEGORIES)],
            'unit_of_measure'         => ['sometimes', 'string', 'max:30'],
            'dosage_form'             => ['nullable', 'string', 'max:50'],
            'current_stock'           => ['sometimes', 'integer', 'min:0'],
            'minimum_stock_level'     => ['sometimes', 'integer', 'min:0'],
            'maximum_stock_level'     => ['nullable', 'integer', 'min:0'],
            'reorder_point'           => ['nullable', 'integer', 'min:0'],
            'expiration_date'         => ['nullable', 'date'],
            'is_controlled_substance' => ['sometimes', 'boolean'],
            'requires_prescription'   => ['sometimes', 'boolean'],
            'is_active'               => ['sometimes', 'boolean'],
            'notes'                   => ['nullable', 'string'],
        ]);

        $inventory->update($validated);

        return response()->json([
            'message' => 'Inventory item updated.',
            'data' => $inventory->fresh(),
        ]);
    }

    public function destroy(Request $request, InventoryItem $inventory): JsonResponse
    {
        $this->authorizeInventory($request, true);

        // Capture the "why" (sent by the web admin) and a full pre-delete
        // snapshot so this deletion is auditable AND restorable from the
        // Delete & Archive History recycle bin.
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = trim((string) ($validated['reason'] ?? '')) ?: 'Inventory item removed by staff.';
        $snapshot = array_merge($inventory->attributesToArray(), ['id' => $inventory->getKey()]);
        $label = $inventory->getAuditLabel();

        DB::transaction(function () use ($request, $inventory, $reason) {
            $table = $inventory->getTable();
            $actorId = $request->user()?->user_id ?? $request->user()?->id;

            // Mark inactive + stamp deleted_by / delete_reason when those columns
            // exist (guarded so a missing column never breaks the delete).
            $updates = ['is_active' => false];

            if (Schema::hasColumn($table, 'deleted_by')) {
                $updates['deleted_by'] = $actorId;
            }
            if (Schema::hasColumn($table, 'delete_reason')) {
                $updates['delete_reason'] = $reason;
            }

            $inventory->forceFill($updates)->save();
            $inventory->delete(); // soft delete (SoftDeletes) — recoverable
        });

        // Delete-history / restore is keyed on this audit log. The action MUST
        // contain "deleted" so AuditController::deleteHistory surfaces it, and
        // the subject id lets AdminDeletedRecordController::restore find the row.
        $this->audit->log(
            $request,
            'inventory.deleted',
            'inventory',
            $inventory,
            $snapshot,
            [],
            [
                'reason' => $reason,
                'delete_reason' => $reason,
                'archive_reason' => $reason,
                'restore_id' => $inventory->getKey(),
                'item_code' => $inventory->item_code,
            ],
            'warning',
            $label
        );

        return response()->json([
            'message' => 'Inventory item removed. It can be restored from Delete & Archive History within 30 days.',
        ]);
    }

    public function stockIn(Request $request, InventoryItem $item): JsonResponse
    {
        $this->authorizeInventory($request);

        $validated = $request->validate([
            'quantity'         => ['required', 'integer', 'min:1'],
            'reference_number' => ['nullable', 'string', 'max:50'],
            'notes'            => ['nullable', 'string', 'max:500'],
        ]);

        $transaction = $this->service->stockIn(
            $item,
            (int) $validated['quantity'],
            $validated
        );

        return response()->json([
            'message'       => "Stock added. New total: {$item->fresh()->current_stock}.",
            'transaction'   => $transaction,
            'current_stock' => $item->fresh()->current_stock,
            'data'          => $item->fresh(),
        ]);
    }

    public function stockOut(Request $request, InventoryItem $item): JsonResponse
    {
        $this->authorizeInventory($request);

        $validated = $request->validate([
            'quantity'        => ['required', 'integer', 'min:1'],
            'reason'          => ['required', 'string', 'max:300'],
            'prescription_id' => ['nullable', 'integer', 'exists:prescriptions,id'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        $transaction = $this->service->stockOut(
            $item,
            (int) $validated['quantity'],
            $validated
        );

        return response()->json([
            'message'       => "Stock deducted. New total: {$item->fresh()->current_stock}.",
            'transaction'   => $transaction,
            'current_stock' => $item->fresh()->current_stock,
            'is_low_stock'  => $item->fresh()->isLowStock(),
            'data'          => $item->fresh(),
        ]);
    }

    public function adjust(Request $request, InventoryItem $item): JsonResponse
    {
        $this->authorizeInventory($request, true);

        $validated = $request->validate([
            'new_quantity' => ['required', 'integer', 'min:0'],
            'reason'       => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $transaction = $this->service->adjust(
            $item,
            (int) $validated['new_quantity'],
            $validated['reason']
        );

        return response()->json([
            'message'     => 'Stock adjusted.',
            'transaction' => $transaction,
            'data'        => $item->fresh(),
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $this->authorizeInventory($request);

        $request->validate([
            'rhu_id' => ['required', 'integer'],
        ]);

        $rhuId = Rhu::scopeRhuId($request->user(), $request->integer('rhu_id'));

        return response()->json([
            'low_stock' => $this->service->getLowStockItems($rhuId),
            'expiring_soon' => $this->service->getExpiringSoon($rhuId, 30),
            'out_of_stock' => InventoryItem::forRhu($rhuId)
                ->active()
                ->where('current_stock', 0)
                ->get(),
        ]);
    }

    public function transactions(Request $request, InventoryItem $item): JsonResponse
    {
        $this->authorizeInventory($request);

        $transactions = $item->transactions()
            ->with(['performedBy'])
            ->latest('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($transactions);
    }
}
