<?php
// app/Services/Prescription/PrescriptionService.php

namespace App\Services\Prescription;

use App\Models\Prescription;
use App\Models\PrescriptionDispensingLog;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditActions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\Prescription\PrescriptionInventoryService;

class PrescriptionService
{
   public function __construct(
    private readonly AuditService $audit,
    private readonly PrescriptionInventoryService $inventorySync
) {}

    // ── Issue ─────────────────────────────────────────────────────────────────

    public function issue(array $data): Prescription
    {
        return DB::transaction(function () use ($data) {
            $hasControlled = collect($data['medications'])
                ->contains(fn($m) => !empty($m['is_controlled']));

            $number = $this->generatePrescriptionNumber((int) $data['rhu_id']);

            $prescription = Prescription::create([
                'resident_profile_id'      => $data['resident_profile_id'],
                'prescribed_by'            => Auth::id(),
                'consultation_id'          => $data['consultation_id'] ?? null,
                'telemedicine_session_id'  => $data['telemedicine_session_id'] ?? null,
                'prescription_number'      => $number,
                'rhu_id'                   => $data['rhu_id'],
                'prescription_date'        => now()->toDateString(),
                'valid_until'              => now()->addDays(7)->toDateString(),
                'diagnosis'                => $data['diagnosis'] ?? null,
                'diagnosis_code'           => $data['diagnosis_code'] ?? null,
                'medications'              => $data['medications'],
                'has_controlled_substances'=> $hasControlled,
                's2_license_number'        => $hasControlled
                    ? ($data['s2_license_number'] ?? null)
                    : null,
                'additional_instructions'  => $data['additional_instructions'] ?? null,
                'dispensing_notes'         => $data['dispensing_notes'] ?? null,
                'status'                   => Prescription::STATUS_ACTIVE,
            ]);

            $this->audit->info(AuditActions::PRESCRIPTION_ISSUED, 'prescription', [
                'subject'       => $prescription,
                'subject_label' => $prescription->getAuditLabel(),
                'new_values'    => [
                    'prescription_number'      => $prescription->prescription_number,
                    'resident_profile_id'      => $prescription->resident_profile_id,
                    'has_controlled_substances'=> $prescription->has_controlled_substances,
                    'medication_count'         => count($prescription->medications),
                ],
            ]);

            return $prescription->fresh([
                'residentProfile.user',
                'prescribedBy',
                'consultation',
                'telemedicineSession',
            ]);
        });
    }

    // ── Dispense ──────────────────────────────────────────────────────────────

   public function dispense(Prescription $prescription, array $data): Prescription
{
    if (!$prescription->isDispensable()) {
        throw new \DomainException(
            "Prescription [{$prescription->prescription_number}] cannot be dispensed. " .
            "Status: [{$prescription->status}]. " .
            ($prescription->isExpired() ? 'This prescription has expired.' : '')
        );
    }

    return DB::transaction(function () use ($prescription, $data) {
        $isPartial = !empty($data['is_partial_dispense']);

        $inventoryResult = [
            'dispensed_items' => $data['dispensed_items'] ?? $prescription->medications,
            'warnings' => [],
            'transactions' => [],
        ];

        $shouldDeductInventory = (bool) ($data['deduct_inventory'] ?? true);

        if ($shouldDeductInventory) {
            $inventoryResult = $this->inventorySync->deductPrescriptionMedicines($prescription, [
                'strict_inventory' => $data['strict_inventory'] ?? true,
                'fail_on_insufficient_stock' => $data['fail_on_insufficient_stock'] ?? true,
            ]);

            $prescription = $prescription->fresh();
        }

        PrescriptionDispensingLog::create([
            'prescription_id'      => $prescription->id,
            'dispensed_by'         => Auth::id(),
            'dispensed_items'      => $inventoryResult['dispensed_items'] ?? $prescription->medications,
            'is_partial_dispense'  => $isPartial,
            'notes'                => $data['notes'] ?? $data['dispensing_notes'] ?? null,
            'dispensed_at'         => now(),
        ]);

        $newStatus = $isPartial
            ? Prescription::STATUS_PARTIALLY_DISPENSED
            : Prescription::STATUS_DISPENSED;

        $oldStatus = $prescription->status;

        $prescription->update([
            'status'           => $newStatus,
            'dispensed_at'     => now(),
            'dispensed_by'     => Auth::id(),
            'dispensing_notes' => $data['notes'] ?? $data['dispensing_notes'] ?? $prescription->dispensing_notes,
        ]);

        $this->audit->info(AuditActions::PRESCRIPTION_DISPENSED, 'prescription', [
            'subject'       => $prescription,
            'subject_label' => $prescription->getAuditLabel(),
            'old_values'    => ['status' => $oldStatus],
            'new_values'    => [
                'status' => $newStatus,
                'is_partial' => $isPartial,
                'dispensed_by' => Auth::id(),
                'inventory_deducted' => $shouldDeductInventory,
                'inventory_warnings' => $inventoryResult['warnings'] ?? [],
            ],
        ]);

        return $prescription->fresh([
            'dispensingLogs.dispensedBy',
            'dispensedBy',
            'residentProfile.user',
            'prescribedBy',
            'consultation',
            'telemedicineSession',
        ]);
    });
}

    // ── Void ──────────────────────────────────────────────────────────────────

    public function void(Prescription $prescription, string $reason): Prescription
    {
        if ($prescription->isTerminal()) {
            throw new \DomainException(
                "Prescription [{$prescription->prescription_number}] " .
                "is already in a terminal state [{$prescription->status}]."
            );
        }

        return DB::transaction(function () use ($prescription, $reason) {
            $oldStatus = $prescription->status;

            $prescription->update([
                'status'    => Prescription::STATUS_VOIDED,
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ]);

            $this->audit->critical(AuditActions::PRESCRIPTION_VOIDED, 'prescription', [
                'subject'       => $prescription,
                'subject_label' => $prescription->getAuditLabel(),
                'old_values'    => ['status' => $oldStatus],
                'new_values'    => [
                    'status'     => Prescription::STATUS_VOIDED,
                    'void_reason'=> $reason,
                    'voided_by'  => Auth::id(),
                ],
            ]);

            return $prescription->fresh(['voidedBy']);
        });
    }

    // ── Expire stale prescriptions (scheduler) ────────────────────────────────

    public function expireStale(): int
    {
        return Prescription::where('status', Prescription::STATUS_ACTIVE)
            ->where('valid_until', '<', today())
            ->update(['status' => Prescription::STATUS_EXPIRED]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function generatePrescriptionNumber(int $rhuId): string
    {
        $year = now()->year;

        // Lock the row to prevent duplicate numbers under concurrent requests
        $count = Prescription::whereYear('created_at', $year)
            ->where('rhu_id', $rhuId)
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('RHU%d-RX-%d-%04d', $rhuId, $year, $count);
    }
}
