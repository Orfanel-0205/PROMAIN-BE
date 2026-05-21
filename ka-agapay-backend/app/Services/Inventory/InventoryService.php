<?php
// app/Services/Inventory/InventoryService.php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(
        private readonly AuditService $audit
    ) {}

    // ── Stock In ──────────────────────────────────────────────────────────────

    public function stockIn(InventoryItem $item, int $quantity, array $data = []): InventoryTransaction
    {
        return DB::transaction(function () use ($item, $quantity, $data) {
            $before = $item->current_stock;
            $after  = $before + $quantity;

            $item->update([
                'current_stock'    => $after,
                'last_restocked_at'=> today(),
            ]);

            return $this->recordTransaction($item, 'stock_in', $before, $quantity, $after, $data);
        });
    }

    // ── Stock Out (manual or from prescription) ───────────────────────────────

    public function stockOut(InventoryItem $item, int $quantity, array $data = []): InventoryTransaction
    {
        if ($item->current_stock < $quantity) {
            throw new \DomainException(
                "Insufficient stock for [{$item->name}]. " .
                "Available: {$item->current_stock}, Requested: {$quantity}."
            );
        }

        return DB::transaction(function () use ($item, $quantity, $data) {
            $before = $item->current_stock;
            $after  = $before - $quantity;

            $item->update(['current_stock' => $after]);

            $transaction = $this->recordTransaction(
                $item, 'stock_out', $before, -$quantity, $after, $data
            );

            // Auto-alert if stock dropped below minimum
            if ($after <= $item->minimum_stock_level) {
                $this->audit->warning('inventory.low_stock_alert', 'inventory', [
                    'subject'       => $item,
                    'subject_label' => $item->getAuditLabel(),
                    'metadata'      => [
                        'current_stock'       => $after,
                        'minimum_stock_level' => $item->minimum_stock_level,
                    ],
                ]);
            }

            return $transaction;
        });
    }

    // ── Adjustment ───────────────────────────────────────────────────────────

    public function adjust(InventoryItem $item, int $newQuantity, string $reason): InventoryTransaction
    {
        return DB::transaction(function () use ($item, $newQuantity, $reason) {
            $before   = $item->current_stock;
            $changed  = $newQuantity - $before;

            $item->update(['current_stock' => $newQuantity]);

            return $this->recordTransaction($item, 'adjustment', $before, $changed, $newQuantity, [
                'reason' => $reason,
            ]);
        });
    }

    // ── Get low stock alerts ──────────────────────────────────────────────────

    public function getLowStockItems(int $rhuId): \Illuminate\Database\Eloquent\Collection
    {
        return InventoryItem::forRhu($rhuId)
            ->active()
            ->lowStock()
            ->orderBy('current_stock')
            ->get();
    }

    // ── Get expiring soon ─────────────────────────────────────────────────────

    public function getExpiringSoon(int $rhuId, int $days = 30): \Illuminate\Database\Eloquent\Collection
    {
        return InventoryItem::forRhu($rhuId)
            ->active()
            ->expiringSoon($days)
            ->orderBy('expiration_date')
            ->get();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function recordTransaction(
        InventoryItem $item,
        string        $type,
        int           $before,
        int           $changed,
        int           $after,
        array         $data = []
    ): InventoryTransaction {
        return InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'performed_by'      => Auth::id(),
            'transaction_type'  => $type,
            'quantity_before'   => $before,
            'quantity_changed'  => $changed,
            'quantity_after'    => $after,
            'prescription_id'   => $data['prescription_id'] ?? null,
            'reference_number'  => $data['reference_number'] ?? null,
            'reason'            => $data['reason'] ?? null,
            'notes'             => $data['notes'] ?? null,
        ]);
    }
}