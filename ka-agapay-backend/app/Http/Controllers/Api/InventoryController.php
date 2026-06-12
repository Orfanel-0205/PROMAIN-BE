<?php
// app/Http/Controllers/Api/InventoryController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $service
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

        $items = InventoryItem::with([
                'transactions' => fn ($q) => $q->latest('created_at')->limit(3),
            ])
            ->forRhu((int) $validated['rhu_id'])
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

    public function store(Request $request): JsonResponse
    {
        $this->authorizeInventory($request);

        $validated = $request->validate([
            'rhu_id'                  => ['required', 'integer'],
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

        $year = now()->year;
        $count = InventoryItem::where('rhu_id', $validated['rhu_id'])
            ->whereYear('created_at', $year)
            ->count() + 1;

        $item = InventoryItem::create(array_merge($validated, [
            'item_code' => sprintf('MED-%d-%04d', $year, $count),
            'is_active' => true,
        ]));

        return response()->json([
            'message' => 'Inventory item created.',
            'data' => $item->fresh(),
        ], 201);
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

        $inventory->update(['is_active' => false]);
        $inventory->delete();

        return response()->json([
            'message' => 'Inventory item removed.',
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

        $rhuId = $request->integer('rhu_id');

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