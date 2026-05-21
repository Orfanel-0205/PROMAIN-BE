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

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin']),
            403
        );

        $request->validate([
            'rhu_id'     => ['required', 'integer'],
            'category'   => ['nullable', 'in:' . implode(',', InventoryItem::CATEGORIES)],
            'low_stock'  => ['nullable', 'boolean'],
            'expiring'   => ['nullable', 'boolean'],
            'search'     => ['nullable', 'string', 'max:100'],
            'per_page'   => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $items = InventoryItem::with(['transactions' => fn($q) => $q->latest('created_at')->limit(3)])
            ->forRhu($request->integer('rhu_id'))
            ->active()
            ->when($request->filled('category'),
                fn($q) => $q->where('category', $request->category))
            ->when($request->boolean('low_stock'),
                fn($q) => $q->lowStock())
            ->when($request->boolean('expiring'),
                fn($q) => $q->expiringSoon(30))
            ->when($request->filled('search'),
                fn($q) => $q->where('name', 'ilike', "%{$request->search}%")
                             ->orWhere('generic_name', 'ilike', "%{$request->search}%")
                             ->orWhere('item_code', 'ilike', "%{$request->search}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin']),
            403
        );

        $validated = $request->validate([
            'rhu_id'                 => ['required', 'integer'],
            'name'                   => ['required', 'string', 'max:200'],
            'generic_name'           => ['nullable', 'string', 'max:200'],
            'category'               => ['required', 'in:' . implode(',', InventoryItem::CATEGORIES)],
            'unit_of_measure'        => ['required', 'string', 'max:30'],
            'dosage_form'            => ['nullable', 'string', 'max:50'],
            'current_stock'          => ['required', 'integer', 'min:0'],
            'minimum_stock_level'    => ['required', 'integer', 'min:0'],
            'reorder_point'          => ['nullable', 'integer', 'min:0'],
            'expiration_date'        => ['nullable', 'date'],
            'is_controlled_substance'=> ['sometimes', 'boolean'],
            'requires_prescription'  => ['sometimes', 'boolean'],
            'notes'                  => ['nullable', 'string'],
        ]);

        $year  = now()->year;
        $count = InventoryItem::where('rhu_id', $validated['rhu_id'])
                     ->whereYear('created_at', $year)->count() + 1;

        $item = InventoryItem::create(array_merge($validated, [
            'item_code' => sprintf('MED-%d-%04d', $year, $count),
        ]));

        return response()->json([
            'message' => 'Inventory item created.',
            'data'    => $item,
        ], 201);
    }

    public function stockIn(Request $request, InventoryItem $item): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin']),
            403
        );

        $validated = $request->validate([
            'quantity'         => ['required', 'integer', 'min:1'],
            'reference_number' => ['nullable', 'string', 'max:50'],
            'notes'            => ['nullable', 'string', 'max:500'],
        ]);

        $transaction = $this->service->stockIn($item, $validated['quantity'], $validated);

        return response()->json([
            'message'       => "Stock added. New total: {$item->fresh()->current_stock}.",
            'transaction'   => $transaction,
            'current_stock' => $item->fresh()->current_stock,
        ]);
    }

    public function stockOut(Request $request, InventoryItem $item): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin']),
            403
        );

        $validated = $request->validate([
            'quantity'        => ['required', 'integer', 'min:1'],
            'reason'          => ['required', 'string', 'max:300'],
            'prescription_id' => ['nullable', 'integer', 'exists:prescriptions,id'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        $transaction = $this->service->stockOut($item, $validated['quantity'], $validated);

        return response()->json([
            'message'       => "Stock deducted. New total: {$item->fresh()->current_stock}.",
            'transaction'   => $transaction,
            'current_stock' => $item->fresh()->current_stock,
            'is_low_stock'  => $item->fresh()->isLowStock(),
        ]);
    }

    public function adjust(Request $request, InventoryItem $item): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin']),
            403
        );

        $validated = $request->validate([
            'new_quantity' => ['required', 'integer', 'min:0'],
            'reason'       => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $transaction = $this->service->adjust($item, $validated['new_quantity'], $validated['reason']);

        return response()->json([
            'message'     => 'Stock adjusted.',
            'transaction' => $transaction,
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin']),
            403
        );

        $request->validate(['rhu_id' => ['required', 'integer']]);

        $rhuId = $request->integer('rhu_id');

        return response()->json([
            'low_stock'      => $this->service->getLowStockItems($rhuId),
            'expiring_soon'  => $this->service->getExpiringSoon($rhuId, 30),
            'out_of_stock'   => InventoryItem::forRhu($rhuId)->active()
                ->where('current_stock', 0)->get(),
        ]);
    }

    public function transactions(Request $request, InventoryItem $item): JsonResponse
    {
        $transactions = $item->transactions()
            ->with(['performedBy'])
            ->latest('created_at')
            ->paginate(20);

        return response()->json($transactions);
    }
}