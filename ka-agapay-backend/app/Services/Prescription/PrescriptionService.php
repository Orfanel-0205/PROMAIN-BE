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

            $consultationId = $this->nullablePositiveId($data['consultation_id'] ?? null);
            $telemedicineSessionId = $this->nullablePositiveId($data['telemedicine_session_id'] ?? null);

            $prescription = Prescription::create([
                'resident_profile_id'       => $data['resident_profile_id'],
                'prescribed_by'             => Auth::id(),
                'consultation_id'           => $consultationId,
                'telemedicine_session_id'   => $telemedicineSessionId,
                'prescription_number'       => $number,
                'rhu_id'                    => $data['rhu_id'],
                'prescription_date'         => now()->toDateString(),
                'valid_until'               => now()->addDays(7)->toDateString(),
                'diagnosis'                 => $data['diagnosis'] ?? null,
                'diagnosis_code'            => $data['diagnosis_code'] ?? null,
                'medications'               => $data['medications'],
                'has_controlled_substances' => $hasControlled,
                's2_license_number'         => $hasControlled
                    ? ($data['s2_license_number'] ?? null)
                    : null,
                'additional_instructions'   => $data['additional_instructions'] ?? null,
                'dispensing_notes'          => $data['dispensing_notes'] ?? null,
                'status'                    => Prescription::STATUS_ACTIVE,
            ]);

            $this->audit->info(AuditActions::PRESCRIPTION_ISSUED, 'prescription', [
                'subject'       => $prescription,
                'subject_label' => $prescription->getAuditLabel(),
                'new_values'    => [
                    'prescription_number'       => $prescription->prescription_number,
                    'resident_profile_id'       => $prescription->resident_profile_id,
                    'has_controlled_substances' => $prescription->has_controlled_substances,
                    'medication_count'          => count($prescription->medications),
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

    /**
     * Record medicine handed over from the RHU drug room, in full or in part.
     *
     * Accountability rules (docs/OPERATIONS.md §9 "Dispensing"):
     *  - the prescription row is locked for the whole operation, so a double
     *    click or two staff at once cannot both dispense and deduct stock twice;
     *  - only what is actually handed over is deducted and logged; the rest can
     *    be dispensed later, and the status stays partially_dispensed until then;
     *  - every dispense names who handed it over (the signed-in account) and who
     *    received it (the patient, or the person collecting for them);
     *  - recording a dispense without deducting RHU stock needs a written reason.
     *
     * $data keys: dispensed_items [{name, quantity_dispensed}] (omit for
     * everything remaining), received_by_name, received_by_relationship, notes,
     * deduct_inventory, strict_inventory, fail_on_insufficient_stock.
     */
    public function dispense(Prescription $prescription, array $data): Prescription
    {
        $receivedBy = trim((string) ($data['received_by_name'] ?? ''));
        $relationship = trim((string) ($data['received_by_relationship'] ?? ''));
        $notes = $data['notes'] ?? $data['dispensing_notes'] ?? null;
        $shouldDeductInventory = (bool) ($data['deduct_inventory'] ?? true);

        if ($receivedBy === '') {
            throw new \DomainException('Enter who received the medicine: the patient, or the person collecting for them.');
        }

        if (!$shouldDeductInventory && trim((string) $notes) === '') {
            throw new \DomainException('Give a reason when recording a dispense without deducting RHU stock.');
        }

        $outcome = DB::transaction(function () use ($prescription, $data, $receivedBy, $relationship, $notes, $shouldDeductInventory) {
            $locked = Prescription::whereKey($prescription->getKey())->lockForUpdate()->firstOrFail();

            if (!$locked->isDispensable()) {
                throw new \DomainException(
                    "Prescription [{$locked->prescription_number}] cannot be dispensed. " .
                    "Status: [{$locked->status}]. " .
                    ($locked->isExpired() ? 'This prescription has expired.' : '')
                );
            }

            $remaining = $locked->remainingQuantities();
            $toGive = $this->quantitiesToDispense($locked, $remaining, $data['dispensed_items'] ?? null);
            $warnings = [];

            if ($shouldDeductInventory) {
                $inventoryResult = $this->inventorySync->deductPrescriptionMedicines($locked, [
                    'strict_inventory' => $data['strict_inventory'] ?? true,
                    'fail_on_insufficient_stock' => $data['fail_on_insufficient_stock'] ?? true,
                    'quantities' => $toGive,
                ]);

                $warnings = $inventoryResult['warnings'] ?? [];
                $locked->refresh();
            }

            $medications = array_values($locked->medications ?? []);
            $given = [];

            foreach ($toGive as $index => $quantity) {
                $prescribed = Prescription::prescribedQuantity($medications[$index]);

                // Running total handed over so far, including this dispense.
                $medications[$index]['dispensed_quantity'] = $prescribed - $remaining[$index] + $quantity;

                $given[] = [
                    'medication_index' => $index,
                    'name' => $medications[$index]['name'] ?? '',
                    'quantity' => $quantity,
                ];
            }

            $isPartial = array_sum($remaining) - array_sum($toGive) > 0;
            $oldStatus = $locked->status;
            $newStatus = $isPartial
                ? Prescription::STATUS_PARTIALLY_DISPENSED
                : Prescription::STATUS_DISPENSED;

            PrescriptionDispensingLog::create([
                'prescription_id'          => $locked->id,
                'dispensed_by'             => Auth::id(),
                'dispensed_items'          => $given,
                'is_partial_dispense'      => $isPartial,
                'received_by_name'         => $receivedBy,
                'received_by_relationship' => $relationship !== '' ? $relationship : null,
                'notes'                    => $notes,
                'dispensed_at'             => now(),
            ]);

            $locked->update([
                'medications'      => $medications,
                'status'           => $newStatus,
                'dispensed_at'     => now(),
                'dispensed_by'     => Auth::id(),
                'dispensing_notes' => $notes ?? $locked->dispensing_notes,
            ]);

            return compact('locked', 'oldStatus', 'newStatus', 'isPartial', 'given', 'warnings');
        });

        // Written after the commit: on Postgres a failed write inside the
        // transaction would abort the dispense itself. Only ids and what
        // changed go in; no copy of the prescription's medical content.
        $prescription = $outcome['locked'];

        $this->audit->info(AuditActions::PRESCRIPTION_DISPENSED, 'prescription', [
            'subject_type'  => 'prescription',
            'subject_id'    => $prescription->id,
            'subject_label' => $prescription->getAuditLabel(),
            'old_values'    => ['status' => $outcome['oldStatus']],
            'new_values'    => [
                'status'                   => $outcome['newStatus'],
                'is_partial'               => $outcome['isPartial'],
                'dispensed_by'             => Auth::id(),
                'received_by_name'         => $receivedBy,
                'received_by_relationship' => $relationship !== '' ? $relationship : null,
                'items'                    => $outcome['given'],
                'inventory_deducted'       => $shouldDeductInventory,
                'inventory_warnings'       => $outcome['warnings'],
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
    }

    /**
     * Which quantities to hand over now (list index => quantity). No items
     * means everything still remaining. Refuses unknown medicines and more than
     * remains, so the stock record always matches what left the drug room.
     *
     * @param array<int, int> $remaining
     * @return array<int, int>
     */
    private function quantitiesToDispense(Prescription $prescription, array $remaining, ?array $items): array
    {
        if (array_sum($remaining) <= 0) {
            throw new \DomainException(
                "Everything on prescription [{$prescription->prescription_number}] has already been dispensed."
            );
        }

        if (empty($items)) {
            return array_filter($remaining, fn (int $quantity) => $quantity > 0);
        }

        $medications = array_values($prescription->medications ?? []);
        $left = $remaining;
        $toGive = [];

        foreach ($items as $item) {
            $label = trim((string) ($item['name'] ?? ''));
            $quantity = (int) ($item['quantity_dispensed'] ?? $item['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $index = null;

            foreach ($medications as $i => $medicine) {
                if (
                    ($left[$i] ?? 0) > 0
                    && \Illuminate\Support\Str::lower(trim((string) ($medicine['name'] ?? ''))) === \Illuminate\Support\Str::lower($label)
                ) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                throw new \DomainException("'{$label}' is not on this prescription, or none of it remains to be dispensed.");
            }

            if ($quantity > $left[$index]) {
                throw new \DomainException("Only {$left[$index]} of {$medications[$index]['name']} remain to be dispensed on this prescription.");
            }

            $toGive[$index] = ($toGive[$index] ?? 0) + $quantity;
            $left[$index] -= $quantity;
        }

        if ($toGive === []) {
            throw new \DomainException('Enter a quantity for at least one medicine.');
        }

        return $toGive;
    }

    // ── Release ───────────────────────────────────────────────────────────────

    /**
     * Record that the prescription was handed to the patient as a document:
     * printed at the RHU, or sent to the app to be filled at an outside pharmacy
     * after telemedicine. The RHU never sees what an outside pharmacy gives, so
     * for those this release is the accountable act: who, when, and how.
     */
    public function recordRelease(Prescription $prescription, string $channel): Prescription
    {
        $prescription->update([
            'released_at' => now(),
            'released_by' => Auth::id(),
        ]);

        $this->audit->info(AuditActions::PRESCRIPTION_RELEASED, 'prescription', [
            'subject_type'  => 'prescription',
            'subject_id'    => $prescription->id,
            'subject_label' => $prescription->getAuditLabel(),
            'new_values'    => [
                'released_by' => Auth::id(),
                'channel'     => $channel,
                'status'      => $prescription->status,
            ],
        ]);

        return $prescription;
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
                'status'      => Prescription::STATUS_VOIDED,
                'voided_at'   => now(),
                'voided_by'   => Auth::id(),
                'void_reason' => $reason,
            ]);

            $this->audit->critical(AuditActions::PRESCRIPTION_VOIDED, 'prescription', [
                'subject'       => $prescription,
                'subject_label' => $prescription->getAuditLabel(),
                'old_values'    => ['status' => $oldStatus],
                'new_values'    => [
                    'status'      => Prescription::STATUS_VOIDED,
                    'void_reason' => $reason,
                    'voided_by'   => Auth::id(),
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

    private function nullablePositiveId(mixed $value): ?int
    {
        $id = (int) ($value ?? 0);

        return $id > 0 ? $id : null;
    }
}