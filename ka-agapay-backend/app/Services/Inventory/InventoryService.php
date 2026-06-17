<?php
// app/Services/Inventory/InventoryService.php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function stockIn(InventoryItem $item, int $quantity, array $data = []): InventoryTransaction
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity to add must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($item, $quantity, $data) {
            $lockedItem = InventoryItem::whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            $quantityBefore = (int) $lockedItem->current_stock;
            $quantityChanged = abs($quantity);
            $quantityAfter = $quantityBefore + $quantityChanged;

            $lockedItem->update([
                'current_stock' => $quantityAfter,
                'last_restocked_at' => now(),
            ]);

            return $this->recordTransaction(
                item: $lockedItem->fresh(),
                transactionType: 'stock_in',
                quantityBefore: $quantityBefore,
                quantityChanged: $quantityChanged,
                quantityAfter: $quantityAfter,
                data: array_merge([
                    'reason' => 'Manual stock addition.',
                    'notes' => 'Stock added from RHU admin inventory.',
                ], $data)
            );
        });
    }

    public function stockOut(InventoryItem $item, int $quantity, array $data = []): InventoryTransaction
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity to deduct must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($item, $quantity, $data) {
            $lockedItem = InventoryItem::whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            $quantityBefore = (int) $lockedItem->current_stock;
            $quantityChanged = abs($quantity);
            $quantityAfter = $quantityBefore - $quantityChanged;

            if ($quantityAfter < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "Insufficient stock for {$lockedItem->name}. Available: {$quantityBefore}, requested: {$quantityChanged}.",
                ]);
            }

            $lockedItem->update([
                'current_stock' => $quantityAfter,
            ]);

            return $this->recordTransaction(
                item: $lockedItem->fresh(),
                transactionType: 'stock_out',
                quantityBefore: $quantityBefore,
                quantityChanged: -$quantityChanged,
                quantityAfter: $quantityAfter,
                data: array_merge([
                    'reason' => 'Manual stock deduction.',
                    'notes' => 'Stock deducted from RHU admin inventory.',
                ], $data)
            );
        });
    }

    public function adjust(InventoryItem $item, int $newQuantity, string $reason): InventoryTransaction
    {
        if ($newQuantity < 0) {
            throw ValidationException::withMessages([
                'new_quantity' => 'New quantity cannot be negative.',
            ]);
        }

        return DB::transaction(function () use ($item, $newQuantity, $reason) {
            $lockedItem = InventoryItem::whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            $quantityBefore = (int) $lockedItem->current_stock;
            $quantityAfter = $newQuantity;
            $quantityChanged = $quantityAfter - $quantityBefore;

            $lockedItem->update([
                'current_stock' => $quantityAfter,
            ]);

            return $this->recordTransaction(
                item: $lockedItem->fresh(),
                transactionType: 'adjustment',
                quantityBefore: $quantityBefore,
                quantityChanged: $quantityChanged,
                quantityAfter: $quantityAfter,
                data: [
                    'reason' => $reason,
                    'notes' => $reason,
                ]
            );
        });
    }

    public function getLowStockItems(int $rhuId)
    {
        return InventoryItem::forRhu($rhuId)
            ->active()
            ->lowStock()
            ->orderBy('name')
            ->get();
    }

    public function getExpiringSoon(int $rhuId, int $days = 30)
    {
        return InventoryItem::forRhu($rhuId)
            ->active()
            ->expiringSoon($days)
            ->orderBy('expiration_date')
            ->get();
    }

    private function recordTransaction(
        InventoryItem $item,
        string $transactionType,
        int $quantityBefore,
        int $quantityChanged,
        int $quantityAfter,
        array $data = []
    ): InventoryTransaction {
        $row = [
            'inventory_item_id' => $item->id,
            'performed_by' => $this->performedById(),
            'transaction_type' => $transactionType,

            // These 3 fields are required for your current table.
            'quantity_before' => $quantityBefore,
            'quantity_changed' => $quantityChanged,
            'quantity_after' => $quantityAfter,

            'reference_number' => $data['reference_number'] ?? null,
            'prescription_id' => $data['prescription_id'] ?? null,
            'reason' => $data['reason'] ?? $this->defaultReason($transactionType),
            'notes' => $data['notes'] ?? null,

            'created_at' => now(),
            'updated_at' => now(),
        ];

        $columns = Schema::hasTable('inventory_transactions')
            ? Schema::getColumnListing('inventory_transactions')
            : [];

        if (!empty($columns)) {
            $row = collect($row)
                ->only($columns)
                ->toArray();
        }

        return InventoryTransaction::create($row);
    }

    private function performedById(): ?int
    {
        $user = Auth::user();

        return $user?->user_id ?? $user?->id ?? Auth::id();
    }

    private function defaultReason(string $transactionType): string
    {
        return match ($transactionType) {
            'stock_in' => 'Stock added.',
            'stock_out' => 'Stock deducted.',
            'adjustment' => 'Stock adjusted.',
            'expiry_removal' => 'Expired stock removed.',
            'transfer' => 'Stock transferred.',
            default => 'Inventory transaction recorded.',
        };
    }
}