<?php
// app/Services/Prescription/PrescriptionInventoryService.php

namespace App\Services\Prescription;

use App\Models\InventoryItem;
use App\Models\Prescription;
use App\Services\Inventory\InventoryService;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PrescriptionInventoryService
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    /**
     * Deduct medicines from RHU inventory when prescription is dispensed
     * or when release is confirmed as "for RHU drug room".
     */
    public function deductPrescriptionMedicines(
        Prescription $prescription,
        array $options = []
    ): array {
        $strictInventory = (bool) ($options['strict_inventory'] ?? true);
        $failOnInsufficient = (bool) ($options['fail_on_insufficient_stock'] ?? true);

        $medications = $this->normalizeMedications($prescription->medications ?? []);
        $updatedMedications = [];
        $dispensedItems = [];
        $transactions = [];
        $warnings = [];

        foreach ($medications as $index => $medicine) {
            $medicineName = trim((string) Arr::get($medicine, 'name', ''));
            $quantity = (int) (
                Arr::get($medicine, 'dispense_quantity')
                ?? Arr::get($medicine, 'quantity')
                ?? Arr::get($medicine, 'qty')
                ?? 1
            );

            $quantity = max($quantity, 1);

            if ($medicineName === '') {
                $updatedMedications[] = array_merge($medicine, [
                    'inventory_status' => 'skipped_no_name',
                ]);

                $warnings[] = [
                    'medicine' => $medicineName ?: "Medicine #" . ($index + 1),
                    'status' => 'skipped_no_name',
                    'message' => 'Medicine name is missing, so inventory was not deducted.',
                ];

                continue;
            }

            $item = $this->findInventoryItem($prescription, $medicine);

            if (!$item) {
                $warning = [
                    'medicine' => $medicineName,
                    'status' => 'not_found',
                    'message' => "No active inventory item matched '{$medicineName}'.",
                    'suggestion' => 'Add this medicine to Inventory or select a valid inventory item before dispensing.',
                ];

                $warnings[] = $warning;

                if ($strictInventory) {
                    throw new DomainException($warning['message'] . ' ' . $warning['suggestion']);
                }

                $updatedMedications[] = array_merge($medicine, [
                    'inventory_status' => 'not_found',
                    'inventory_warning' => $warning['message'],
                ]);

                continue;
            }

            if ($item->current_stock < $quantity) {
                $warning = [
                    'medicine' => $medicineName,
                    'inventory_item_id' => $item->id,
                    'available' => (int) $item->current_stock,
                    'requested' => $quantity,
                    'status' => 'insufficient_stock',
                    'message' => "Insufficient stock for {$item->name}. Available: {$item->current_stock}, requested: {$quantity}.",
                    'suggestion' => 'Restock this item or edit the prescription quantity before dispensing.',
                ];

                $warnings[] = $warning;

                if ($failOnInsufficient) {
                    throw new DomainException($warning['message'] . ' ' . $warning['suggestion']);
                }

                $updatedMedications[] = array_merge($medicine, [
                    'inventory_item_id' => $item->id,
                    'inventory_status' => 'insufficient_stock',
                    'inventory_warning' => $warning['message'],
                ]);

                continue;
            }

            $transaction = $this->inventoryService->stockOut($item, $quantity, [
                'prescription_id' => $prescription->id,
                'reason' => 'E-prescription dispensed from RHU drug room.',
                'notes' => "Auto-deducted from prescription {$prescription->prescription_number}.",
            ]);

            $freshItem = $item->fresh();

            $transactions[] = $transaction;

            $dispensedItems[] = [
                'name' => $medicineName,
                'inventory_item_id' => $item->id,
                'inventory_transaction_id' => $transaction->id,
                'quantity' => $quantity,
                'unit' => $item->unit_of_measure,
                'stock_before' => $transaction->quantity_before,
                'stock_after' => $transaction->quantity_after,
                'low_stock_after' => $freshItem?->isLowStock() ?? false,
            ];

            $updatedMedications[] = array_merge($medicine, [
                'inventory_item_id' => $item->id,
                'inventory_transaction_id' => $transaction->id,
                'inventory_status' => 'deducted',
                'dispensed_quantity' => $quantity,
                'stock_after_dispense' => $transaction->quantity_after,
                'low_stock_after_dispense' => $freshItem?->isLowStock() ?? false,
            ]);

            if ($freshItem && $freshItem->isLowStock()) {
                $warnings[] = [
                    'medicine' => $medicineName,
                    'inventory_item_id' => $item->id,
                    'status' => 'low_stock',
                    'message' => "{$item->name} is now low stock after dispensing.",
                    'current_stock' => (int) $freshItem->current_stock,
                    'minimum_stock_level' => (int) $freshItem->minimum_stock_level,
                    'suggestion' => 'Prepare restocking request.',
                ];
            }
        }

        $prescription->update([
            'medications' => $updatedMedications,
        ]);

        return [
            'dispensed_items' => $dispensedItems,
            'updated_medications' => $updatedMedications,
            'transactions' => $transactions,
            'warnings' => $warnings,
        ];
    }

    private function normalizeMedications(mixed $medications): array
    {
        if (is_array($medications)) {
            return $medications;
        }

        if (is_string($medications)) {
            $decoded = json_decode($medications, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function findInventoryItem(Prescription $prescription, array $medicine): ?InventoryItem
    {
        $inventoryItemId = Arr::get($medicine, 'inventory_item_id');

        if ($inventoryItemId) {
            return InventoryItem::where('id', $inventoryItemId)
                ->where('rhu_id', $prescription->rhu_id)
                ->active()
                ->first();
        }

        $name = trim((string) Arr::get($medicine, 'name', ''));
        $genericName = trim((string) Arr::get($medicine, 'generic_name', ''));

        if ($name === '' && $genericName === '') {
            return null;
        }

        $searchTerms = collect([$name, $genericName])
            ->filter()
            ->map(fn ($value) => Str::lower(trim((string) $value)))
            ->unique()
            ->values();

        return InventoryItem::where('rhu_id', $prescription->rhu_id)
            ->active()
            ->where('category', 'medicine')
            ->where(function ($query) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $query->orWhereRaw('LOWER(name) = ?', [$term])
                        ->orWhereRaw('LOWER(generic_name) = ?', [$term])
                        ->orWhereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(generic_name) LIKE ?', ["%{$term}%"]);
                }
            })
            ->orderByDesc('current_stock')
            ->first();
    }
}