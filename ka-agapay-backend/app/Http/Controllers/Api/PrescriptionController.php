<?php

// app/Http/Controllers/Api/PrescriptionController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DispensePrescriptionRequest;
use App\Http\Resources\Prescription\PrescriptionResource;
use App\Models\Prescription;
use App\Services\Prescription\PrescriptionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PrescriptionController extends Controller
{
    /**
     * Roles allowed to CREATE / ISSUE a prescription order.
     * Nurses, midwives, BHWs, and head nurses are intentionally excluded — they
     * may only release/dispense an existing valid order, never create one.
     */
    private const PRESCRIBER_ROLES = [
        'doctor',
        'mho',
        'mho_admin',
        'super_admin',
        'superadmin',
    ];

    public function __construct(
        private readonly PrescriptionService $service
    ) {}

    /**
     * Enforce that only a Doctor, MHO, or Super Admin can create/issue a
     * prescription. Enforced server-side so a bypassed frontend cannot create
     * orders. Returns a clear 403 for everyone else.
     */
    private function authorizePrescriber(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole(self::PRESCRIBER_ROLES),
            403,
            'Only a Doctor, MHO, or Super Admin can create or issue prescriptions. Nurses, midwives, BHWs, and head nurses may only release or dispense an existing prescription.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $query = DB::table('prescriptions as p')
            ->leftJoin('resident_profiles as rp', 'rp.id', '=', 'p.resident_profile_id')
            ->leftJoin('users as u', 'u.user_id', '=', 'rp.user_id')
            ->leftJoin('users as d', 'd.user_id', '=', 'p.prescribed_by')
            ->selectRaw("p.*, CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as patient_name")
            ->selectRaw("CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, '')) as prescriber_name");

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('p.status', $request->query('status'));
        }

        if ($request->filled('consultation_id')) {
            $query->where('p.consultation_id', $request->integer('consultation_id'));
        }

        if ($request->filled('resident_profile_id')) {
            $query->where('p.resident_profile_id', $request->integer('resident_profile_id'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));

            $query->where(function ($q) use ($search) {
                $q->where('p.prescription_number', 'ilike', "%{$search}%")
                    ->orWhere('p.diagnosis', 'ilike', "%{$search}%")
                    ->orWhere('p.medications', 'ilike', "%{$search}%")
                    ->orWhereRaw("CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) ILIKE ?", ["%{$search}%"]);
            });
        }

        $rows = $query
            ->latest('p.prescription_date')
            ->latest('p.id')
            ->paginate($request->integer('per_page', 20));

        $rows->getCollection()->transform(fn ($row) => $this->formatPrescription($row));

        return response()->json($rows);
    }

    public function mine(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');
        abort_unless(Schema::hasTable('resident_profiles'), 404, 'Resident profiles table not found.');

        $userId = $request->user()->user_id ?? $request->user()->getKey();

        $profile = DB::table('resident_profiles')
            ->where('user_id', $userId)
            ->first();

        if (!$profile) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $request->integer('per_page', 20),
                    'total' => 0,
                ],
            ]);
        }

        $query = DB::table('prescriptions as p')
            ->leftJoin('resident_profiles as rp', 'rp.id', '=', 'p.resident_profile_id')
            ->leftJoin('users as u', 'u.user_id', '=', 'rp.user_id')
            ->leftJoin('users as d', 'd.user_id', '=', 'p.prescribed_by')
            ->where('p.resident_profile_id', $profile->id)
            ->selectRaw("p.*, CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as patient_name")
            ->selectRaw("CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, '')) as prescriber_name");

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('p.status', $request->query('status'));
        }

        $rows = $query
            ->latest('p.prescription_date')
            ->latest('p.id')
            ->paginate($request->integer('per_page', 20));

        $rows->getCollection()->transform(fn ($row) => $this->formatPrescription($row));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePrescriber($request);

        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $formType = $request->input('form_type') === 'lab_request'
            ? 'lab_request'
            : 'medicine';

        $validated = $request->validate([
            'form_type'                  => ['nullable', Rule::in(['medicine', 'lab_request'])],
            'resident_profile_id'        => ['required', 'integer'],
            'consultation_id'            => ['nullable', 'integer'],
            'telemedicine_session_id'    => ['nullable', 'integer'],
            'rhu_id'                     => ['nullable', 'integer'],
            'diagnosis'                  => ['nullable', 'string', 'max:1000'],
            'diagnosis_code'             => ['nullable', 'string', 'max:20'],
            'clinical_impression'        => ['nullable', 'string', 'max:1000'],
            'request_reason'             => ['nullable', 'string', 'max:2000'],
            'priority'                   => ['nullable', Rule::in(['routine', 'urgent', 'stat'])],
            'request_notes'              => ['nullable', 'string', 'max:2000'],
            'lab_tests'                  => ['nullable', 'array'],
            'medications'                => [$formType === 'medicine' ? 'required' : 'nullable', 'array'],
            'additional_instructions'    => ['nullable', 'string', 'max:2000'],
            'dispensing_notes'           => ['nullable', 'string', 'max:2000'],
        ]);

        if ($formType === 'medicine' && count($validated['medications'] ?? []) < 1) {
            throw ValidationException::withMessages([
                'medications' => ['Add at least one medicine.'],
            ]);
        }

        $labTests = $this->normalizeLabTests($validated['lab_tests'] ?? []);

        if ($formType === 'lab_request' && !$this->hasLabRequestSelection($labTests)) {
            throw ValidationException::withMessages([
                'lab_tests' => ['Select at least one laboratory, X-ray, ultrasound test, or enter an Others field.'],
            ]);
        }

        $user = $request->user();
        $authUserId = $this->currentUserId($request);
        $rhuId = (int) ($validated['rhu_id'] ?? $user->barangay_id ?? 1);
        $number = $this->nextPrescriptionNumber($rhuId);

        $consultationId = $this->nullableExistingId(
            'consultations',
            $validated['consultation_id'] ?? null
        );

        $telemedicineSessionId = $this->nullableExistingId(
            'telemedicine_sessions',
            $validated['telemedicine_session_id'] ?? null
        );

        $id = DB::table('prescriptions')->insertGetId([
            'resident_profile_id' => $validated['resident_profile_id'],
            'prescribed_by' => $authUserId,
            'consultation_id' => $consultationId,
            'telemedicine_session_id' => $telemedicineSessionId,
            'form_type' => $formType,
            'prescription_number' => $number,
            'rhu_id' => $rhuId,
            'prescription_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'diagnosis' => $validated['diagnosis'] ?? null,
            'diagnosis_code' => $validated['diagnosis_code'] ?? null,
            'clinical_impression' => $validated['clinical_impression'] ?? $validated['diagnosis'] ?? null,
            'request_reason' => $validated['request_reason'] ?? null,
            'priority' => $validated['priority'] ?? 'routine',
            'request_notes' => $validated['request_notes'] ?? null,
            'lab_tests' => $formType === 'lab_request' ? json_encode($labTests) : null,
            'medications' => json_encode($formType === 'medicine' ? $validated['medications'] : []),
            'has_controlled_substances' => $formType === 'medicine' && collect($validated['medications'] ?? [])
                ->contains(fn ($m) => (bool) ($m['is_controlled'] ?? false)),
            'additional_instructions' => $validated['additional_instructions'] ?? null,
            'dispensing_notes' => $validated['dispensing_notes'] ?? null,
            'status' => 'active',
            'file_path' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('prescriptions')->where('id', $id)->first();
        $pdfPath = $this->generateAndStoreModernPdf($row);

        DB::table('prescriptions')->where('id', $id)->update([
            'file_path' => $pdfPath,
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => $formType === 'lab_request'
                ? 'Lab request created successfully.'
                : 'Prescription issued successfully.',
            'data' => $this->formatPrescription(
                DB::table('prescriptions')->where('id', $id)->first()
            ),
        ], 201);
    }

    public function fromConsultation(Request $request, int $id): JsonResponse
    {
        $this->authorizePrescriber($request);

        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');
        abort_unless(Schema::hasTable('consultations'), 404, 'Consultations table not found.');

        $consultation = DB::table('consultations')->where('id', $id)->first();
        abort_unless($consultation, 404, 'Consultation not found.');

        $residentProfile = Schema::hasTable('resident_profiles')
            ? DB::table('resident_profiles')->where('user_id', $consultation->user_id)->first()
            : null;

        abort_unless($residentProfile, 422, 'Patient profile not found for this consultation.');

        $validated = $request->validate([
            'diagnosis' => ['nullable', 'string', 'max:1000'],
            'diagnosis_code' => ['nullable', 'string', 'max:20'],
            'medications' => ['nullable', 'array'],
            'additional_instructions' => ['nullable', 'string', 'max:2000'],
            'dispensing_notes' => ['nullable', 'string', 'max:2000'],
            'rhu_id' => ['nullable', 'integer'],
        ]);

        $diagnosis = $this->firstNonEmpty([
            $validated['diagnosis'] ?? null,
            $consultation->diagnosis ?? null,
            $consultation->assessment ?? null,
        ]);

        $medications = !empty($validated['medications'])
            ? $validated['medications']
            : $this->medicationsFromConsultation($consultation);

        $additionalInstructions = $this->firstNonEmpty([
            $validated['additional_instructions'] ?? null,
            $consultation->plan ?? null,
            $consultation->treatment ?? null,
        ]);

        $existing = DB::table('prescriptions')
            ->where('consultation_id', $consultation->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($existing) {
            DB::table('prescriptions')->where('id', $existing->id)->update([
                'form_type' => 'medicine',
                'diagnosis' => $diagnosis,
                'diagnosis_code' => $validated['diagnosis_code'] ?? $existing->diagnosis_code,
                'medications' => json_encode($medications),
                'has_controlled_substances' => collect($medications)
                    ->contains(fn ($m) => (bool) ($m['is_controlled'] ?? false)),
                'additional_instructions' => $additionalInstructions,
                'dispensing_notes' => $validated['dispensing_notes'] ?? $existing->dispensing_notes,
                'updated_at' => now(),
            ]);

            $row = DB::table('prescriptions')->where('id', $existing->id)->first();
            $pdfPath = $this->generateAndStoreModernPdf($row);

            DB::table('prescriptions')->where('id', $existing->id)->update([
                'file_path' => $pdfPath,
                'updated_at' => now(),
            ]);

            return response()->json([
                'message' => 'Existing e-prescription opened and updated from SOAP.',
                'data' => $this->formatPrescription(
                    DB::table('prescriptions')->where('id', $existing->id)->first()
                ),
            ]);
        }

        $authUserId = $this->currentUserId($request);
        $rhuId = (int) (
            $validated['rhu_id']
            ?? $request->user()?->effectiveRhuId()
            ?? $residentProfile->barangay_id
            ?? 1
        );
        $number = $this->nextPrescriptionNumber($rhuId);

        $prescriptionId = DB::table('prescriptions')->insertGetId([
            'resident_profile_id' => $residentProfile->id,
            'prescribed_by' => $authUserId,
            'consultation_id' => $consultation->id,
            'telemedicine_session_id' => null,
            'form_type' => 'medicine',
            'prescription_number' => $number,
            'rhu_id' => $rhuId,
            'prescription_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'diagnosis' => $diagnosis,
            'diagnosis_code' => $validated['diagnosis_code'] ?? null,
            'medications' => json_encode($medications),
            'has_controlled_substances' => collect($medications)
                ->contains(fn ($m) => (bool) ($m['is_controlled'] ?? false)),
            'additional_instructions' => $additionalInstructions,
            'dispensing_notes' => $validated['dispensing_notes'] ?? null,
            'status' => 'active',
            'file_path' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('prescriptions')->where('id', $prescriptionId)->first();
        $pdfPath = $this->generateAndStoreModernPdf($row);

        DB::table('prescriptions')->where('id', $prescriptionId)->update([
            'file_path' => $pdfPath,
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'E-prescription created from SOAP.',
            'data' => $this->formatPrescription(
                DB::table('prescriptions')->where('id', $prescriptionId)->first()
            ),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $row = DB::table('prescriptions')->where('id', $id)->first();
        abort_unless($row, 404, 'Prescription not found.');

        return response()->json([
            'data' => $this->formatPrescription($row),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizePrescriber($request);

        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $validated = $request->validate([
            'status'                  => ['nullable', 'string', 'max:30'],
            'diagnosis'               => ['nullable', 'string', 'max:1000'],
            'medications'             => ['nullable', 'array'],
            'clinical_impression'     => ['nullable', 'string', 'max:1000'],
            'request_reason'          => ['nullable', 'string', 'max:2000'],
            'priority'                => ['nullable', Rule::in(['routine', 'urgent', 'stat'])],
            'request_notes'           => ['nullable', 'string', 'max:2000'],
            'lab_tests'               => ['nullable', 'array'],
            'additional_instructions' => ['nullable', 'string', 'max:2000'],
            'dispensing_notes'        => ['nullable', 'string', 'max:2000'],
        ]);

        $updates = collect($validated)
            ->map(fn ($value, $key) => in_array($key, ['medications', 'lab_tests'], true) ? json_encode($value) : $value)
            ->all();

        $updates['updated_at'] = now();

        DB::table('prescriptions')->where('id', $id)->update($updates);

        $row = DB::table('prescriptions')->where('id', $id)->first();

        if ($row) {
            $pdfPath = $this->generateAndStoreModernPdf($row);

            DB::table('prescriptions')->where('id', $id)->update([
                'file_path' => $pdfPath,
                'updated_at' => now(),
            ]);
        }

        return $this->show($id);
    }

    public function destroy(int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        DB::table('prescriptions')->where('id', $id)->update([
            'status' => 'cancelled',
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Prescription cancelled.',
        ]);
    }

    public function release(Request $request, int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $row = DB::table('prescriptions')->where('id', $id)->first();
        abort_unless($row, 404, 'Prescription not found.');

        $pdfPath = $this->generateAndStoreModernPdf($row);

        DB::table('prescriptions')->where('id', $id)->update([
            'file_path' => $pdfPath,
            'updated_at' => now(),
        ]);

        $isLabRequest = ($row->form_type ?? 'medicine') === 'lab_request';

        if (!$isLabRequest && $request->boolean('dispense_from_rhu')) {
            $prescription = Prescription::findOrFail($id);

            $this->service->dispense($prescription->fresh(), [
                'deduct_inventory' => true,
                'strict_inventory' => $request->boolean('strict_inventory', true),
                'fail_on_insufficient_stock' => true,
                'notes' => $request->input(
                    'dispensing_notes',
                    'Auto-dispensed from RHU drug room during e-prescription release.'
                ),
            ]);
        }

        return response()->json([
            'message' => $isLabRequest
                ? 'Lab request PDF released.'
                : ($request->boolean('dispense_from_rhu')
                ? 'Prescription PDF released and inventory deducted.'
                : 'Prescription PDF released.'),
            'data' => $this->formatPrescription(
                DB::table('prescriptions')->where('id', $id)->first()
            ),
        ]);
    }

    public function dispense(DispensePrescriptionRequest|Request $request, Prescription|int $prescription): JsonResponse
    {
        $prescriptionModel = $prescription instanceof Prescription
            ? $prescription
            : Prescription::findOrFail($prescription);

        if (($prescriptionModel->form_type ?? 'medicine') === 'lab_request') {
            return response()->json([
                'message' => 'Lab requests cannot be dispensed from inventory.',
            ], 422);
        }

        $validated = method_exists($request, 'validated')
            ? $request->validated()
            : $request->validate([
                'dispensed_items' => ['nullable', 'array'],
                'is_partial_dispense' => ['nullable', 'boolean'],
                'notes' => ['nullable', 'string', 'max:1000'],
                'dispensing_notes' => ['nullable', 'string', 'max:1000'],
                'deduct_inventory' => ['nullable', 'boolean'],
                'strict_inventory' => ['nullable', 'boolean'],
                'fail_on_insufficient_stock' => ['nullable', 'boolean'],
            ]);

        $prescriptionModel = $this->service->dispense($prescriptionModel, array_merge($validated, [
            'deduct_inventory' => $request->boolean('deduct_inventory', true),
            'strict_inventory' => $request->boolean('strict_inventory', true),
            'fail_on_insufficient_stock' => $request->boolean('fail_on_insufficient_stock', true),
        ]));

        $row = DB::table('prescriptions')->where('id', $prescriptionModel->id)->first();

        if ($row) {
            $pdfPath = $this->generateAndStoreModernPdf($row);

            DB::table('prescriptions')->where('id', $prescriptionModel->id)->update([
                'file_path' => $pdfPath,
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Prescription dispensed and inventory deducted.',
            'data' => new PrescriptionResource($prescriptionModel->fresh([
                'dispensingLogs.dispensedBy',
                'dispensedBy',
                'residentProfile.user',
                'prescribedBy',
                'consultation',
                'telemedicineSession',
            ])),
        ]);
    }

    public function downloadPdf(int $id)
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $row = DB::table('prescriptions')->where('id', $id)->first();
        abort_unless($row, 404, 'Prescription not found.');

        /*
         * IMPORTANT:
         * Always regenerate using the modern template.
         * This prevents old file_path PDFs from showing the old plain format.
         */
        $pdfBytes = $this->renderModernPdf($row);

        $filename = Str::slug($row->prescription_number ?? ('prescription-' . $row->id)) . '.pdf';

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    private function formatPrescription(object $row): array
    {
        $data = (array) $row;

        $data['form_type'] = $row->form_type ?? 'medicine';
        $data['medications'] = $this->decodeMedications($row->medications ?? '[]');
        $data['lab_tests'] = $this->decodeJsonArray($row->lab_tests ?? null);
        $data['clinical_impression'] = $row->clinical_impression ?? null;
        $data['request_reason'] = $row->request_reason ?? null;
        $data['priority'] = $row->priority ?? null;
        $data['request_notes'] = $row->request_notes ?? null;
        $data['pdf_url'] = !empty($row->file_path)
            ? Storage::disk('public')->url($row->file_path)
            : null;
        $data['pdf_endpoint'] = url('/api/v1/prescriptions/' . $row->id . '/pdf');

        return $data;
    }

    private function decodeMedications(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function decodeJsonArray(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!$raw) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nextPrescriptionNumber(int $rhuId): string
    {
        $prefix = 'RHU' . $rhuId . '-RX-' . now()->format('Y');
        $count = DB::table('prescriptions')
            ->where('prescription_number', 'like', $prefix . '%')
            ->count() + 1;

        return $prefix . '-' . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
    }

    private function patientName(int $residentProfileId): string
    {
        if (!Schema::hasTable('resident_profiles') || !Schema::hasTable('users')) {
            return 'Resident Profile #' . $residentProfileId;
        }

        $row = DB::table('resident_profiles as rp')
            ->leftJoin('users as u', 'u.user_id', '=', 'rp.user_id')
            ->where('rp.id', $residentProfileId)
            ->selectRaw("CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as name")
            ->first();

        return trim((string) ($row->name ?? '')) ?: 'Resident Profile #' . $residentProfileId;
    }

    private function prescriberName(?int $userId): string
    {
        $userId = (int) ($userId ?? 0);

        if ($userId <= 0 || !Schema::hasTable('users')) {
            return 'Authorized RHU Staff';
        }

        $row = DB::table('users')
            ->where('user_id', $userId)
            ->selectRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as name")
            ->first();

        return trim((string) ($row->name ?? '')) ?: 'Authorized RHU Staff';
    }

    private function medicinesToText(array $medicines): string
    {
        if (empty($medicines)) {
            return 'No medicines listed.';
        }

        return collect($medicines)->map(function ($med, $index) {
            if (is_string($med)) {
                return ($index + 1) . '. ' . $med;
            }

            return ($index + 1) . '. ' . ($med['name'] ?? 'Medicine')
                . (!empty($med['dosage']) ? ' - ' . $med['dosage'] : '')
                . (!empty($med['frequency']) ? ' - ' . $med['frequency'] : '')
                . (!empty($med['duration']) ? ' - ' . $med['duration'] : '')
                . (!empty($med['route']) ? ' - ' . $med['route'] : '')
                . (!empty($med['instructions']) ? ' - ' . $med['instructions'] : '');
        })->implode("\n");
    }

    private function normalizeMedicinesForPdf(array $medicines): array
    {
        if (empty($medicines)) {
            return [[
                'name' => 'Medication as prescribed',
                'dosage' => '',
                'quantity' => '',
                'frequency' => '',
                'duration' => '',
                'route' => '',
                'instructions' => 'Take as directed by RHU staff.',
            ]];
        }

        return collect($medicines)
            ->map(function ($medicine, $index) {
                if (is_string($medicine)) {
                    return [
                        'name' => $medicine,
                        'dosage' => '',
                        'quantity' => '',
                        'frequency' => '',
                        'duration' => '',
                        'route' => '',
                        'instructions' => '',
                    ];
                }

                return [
                    'name' => $medicine['name']
                        ?? $medicine['medicine']
                        ?? $medicine['medicine_name']
                        ?? $medicine['drug']
                        ?? ('Medicine #' . ($index + 1)),
                    'dosage' => $medicine['dosage']
                        ?? $medicine['dose']
                        ?? $medicine['strength']
                        ?? '',
                    'quantity' => $medicine['quantity']
                        ?? $medicine['qty']
                        ?? '',
                    'frequency' => $medicine['frequency']
                        ?? $medicine['freq']
                        ?? '',
                    'duration' => $medicine['duration']
                        ?? '',
                    'route' => $medicine['route']
                        ?? '',
                    'instructions' => $medicine['instructions']
                        ?? $medicine['instruction']
                        ?? $medicine['sig']
                        ?? '',
                ];
            })
            ->values()
            ->all();
    }

    private function medicationsFromConsultation(object $consultation): array
    {
        $text = $this->firstNonEmpty([
            $consultation->prescribed_drugs ?? null,
            $consultation->treatment ?? null,
            $consultation->plan ?? null,
        ]);

        return [[
            'name' => $text ? $this->firstLine($text) : 'Medication as prescribed',
            'dosage' => '',
            'quantity' => '',
            'frequency' => '',
            'duration' => '',
            'route' => '',
            'instructions' => $text ?: 'Take as directed by RHU staff.',
        ]];
    }

    private function firstLine(string $value): string
    {
        $line = trim((string) preg_split('/\r\n|\r|\n/', $value)[0]);

        return $line !== '' ? $line : 'Medication as prescribed';
    }

    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $text = trim((string) ($value ?? ''));

            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function generateAndStoreModernPdf(object $row): string
    {
        $number = $row->prescription_number ?? ('RX-' . $row->id);
        $folder = ($row->form_type ?? 'medicine') === 'lab_request'
            ? 'prescriptions/lab-requests'
            : 'prescriptions/manual';
        $path = $folder . '/' . Str::slug($number) . '.pdf';

        Storage::disk('public')->put($path, $this->renderModernPdf($row));

        return $path;
    }

    private function renderModernPdf(object $row): string
    {
        if (($row->form_type ?? 'medicine') === 'lab_request') {
            return $this->renderLabRequestPdf($row);
        }

        $medications = $this->decodeMedications($row->medications ?? '[]');

        $data = [
            'prescriptionNo' => $row->prescription_number ?? ('RX-' . $row->id),
            'date' => $this->formatLongDate($row->prescription_date ?? now()->toDateString()),
            'validUntil' => $this->formatLongDate($row->valid_until ?? now()->addDays(7)->toDateString()),
            'patientName' => $this->patientName((int) $row->resident_profile_id),
            'doctorName' => $this->prescriberName((int) ($row->prescribed_by ?? 0)),
            'diagnosis' => $row->diagnosis ?: 'For clinical management',
            'diagnosisCode' => $row->diagnosis_code ?? null,
            'medicines' => $this->normalizeMedicinesForPdf($medications),
            'additionalInstructions' => $row->additional_instructions
                ?: 'Follow the prescribed dosage. Return to RHU if symptoms persist or worsen.',
            'dispensingNotes' => $row->dispensing_notes ?? null,
            'status' => $row->status ?? 'active',
            'rhuName' => 'RHU Malasiqui',
            'municipality' => 'Malasiqui, Pangasinan',
        ];

        return Pdf::loadView('pdf.prescription-modern', $data)
            ->setPaper('a4', 'portrait')
            ->output();
    }

    private function renderLabRequestPdf(object $row): string
    {
        $data = [
            'requestNo' => $row->prescription_number ?? ('LAB-' . $row->id),
            'date' => $this->formatLongDate($row->prescription_date ?? now()->toDateString()),
            'patientName' => $this->patientName((int) $row->resident_profile_id),
            'ageSex' => $this->patientAgeSex((int) $row->resident_profile_id),
            'clinicalImpression' => $row->clinical_impression ?: ($row->diagnosis ?: ''),
            'consultationId' => $row->consultation_id ?? null,
            'requestedBy' => $this->prescriberName((int) ($row->prescribed_by ?? 0)),
            'licenseNumber' => $this->prescriberLicenseNumber((int) ($row->prescribed_by ?? 0)),
            'priority' => $row->priority ?: 'routine',
            'reason' => $row->request_reason ?? '',
            'notes' => $row->request_notes ?? '',
            'labTests' => $this->normalizeLabTests($this->decodeJsonArray($row->lab_tests ?? null)),
            'laboratoryOptions' => $this->laboratoryOptions(),
            'xrayOptions' => $this->xrayOptions(),
            'ultrasoundOptions' => $this->ultrasoundOptions(),
        ];

        return Pdf::loadView('pdf.lab-request', $data)
            ->setPaper('a4', 'portrait')
            ->output();
    }

    private function laboratoryOptions(): array
    {
        return ['CBC', 'Urinalysis', 'Fecalysis', 'FBS', 'HBA1C', 'B.U.A', 'ALT', 'AST', 'Creatinine', 'B.U.N', 'Total Lipid Profile'];
    }

    private function xrayOptions(): array
    {
        return ['CXR - PA View', 'CXR - Apicolordotic View'];
    }

    private function ultrasoundOptions(): array
    {
        return ['Whole Abdomen', 'Lower Abdomen', 'Upper Abdomen', 'Prostate', 'HBT', 'KUB'];
    }

    private function normalizeLabTests(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];
        $others = is_array($data['others'] ?? null) ? $data['others'] : [];

        return [
            'laboratory' => array_values(array_filter(array_map('strval', $data['laboratory'] ?? []))),
            'xray' => array_values(array_filter(array_map('strval', $data['xray'] ?? []))),
            'ultrasound' => array_values(array_filter(array_map('strval', $data['ultrasound'] ?? []))),
            'others' => [
                'laboratory' => trim((string) ($others['laboratory'] ?? '')),
                'xray' => trim((string) ($others['xray'] ?? '')),
                'ultrasound' => trim((string) ($others['ultrasound'] ?? '')),
            ],
        ];
    }

    private function hasLabRequestSelection(array $labTests): bool
    {
        return count($labTests['laboratory'] ?? []) > 0
            || count($labTests['xray'] ?? []) > 0
            || count($labTests['ultrasound'] ?? []) > 0
            || trim((string) ($labTests['others']['laboratory'] ?? '')) !== ''
            || trim((string) ($labTests['others']['xray'] ?? '')) !== ''
            || trim((string) ($labTests['others']['ultrasound'] ?? '')) !== '';
    }

    private function patientAgeSex(int $residentProfileId): string
    {
        if (!Schema::hasTable('resident_profiles') || !Schema::hasTable('users')) {
            return '';
        }

        $row = DB::table('resident_profiles as rp')
            ->leftJoin('users as u', 'u.user_id', '=', 'rp.user_id')
            ->where('rp.id', $residentProfileId)
            ->selectRaw('rp.*, u.birthday as user_birthday, u.sex as user_sex')
            ->first();

        if (!$row) {
            return '';
        }

        $birth = $row->user_birthday ?? $row->birth_date ?? $row->birthdate ?? $row->date_of_birth ?? null;
        $age = '';

        if ($birth) {
            try {
                $age = (string) \Carbon\Carbon::parse($birth)->age;
            } catch (\Throwable) {
                $age = '';
            }
        }

        $sex = trim((string) ($row->user_sex ?? $row->profile_sex ?? $row->gender ?? ''));

        return trim($age . ($age && $sex ? ' / ' : '') . $sex);
    }

    private function prescriberLicenseNumber(?int $userId): ?string
    {
        $userId = (int) ($userId ?? 0);

        if ($userId <= 0 || !Schema::hasTable('users')) {
            return null;
        }

        $columns = Schema::getColumnListing('users');
        $candidateColumns = [
            'license_number',
            'prc_license_number',
            'prc_license_no',
            'professional_license_number',
            's2_license_number',
        ];

        $selected = array_values(array_filter($candidateColumns, fn ($column) => in_array($column, $columns, true)));

        if (!$selected) {
            return null;
        }

        $row = DB::table('users')
            ->where('user_id', $userId)
            ->first($selected);

        foreach ($selected as $column) {
            $value = trim((string) ($row->{$column} ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function formatLongDate(?string $value): string
    {
        if (!$value) {
            return now()->format('F d, Y');
        }

        try {
            return \Carbon\Carbon::parse($value)->format('F d, Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function nullableExistingId(string $table, mixed $value, string $column = 'id'): ?int
    {
        $id = (int) ($value ?? 0);

        if ($id <= 0) {
            return null;
        }

        if (!Schema::hasTable($table)) {
            return null;
        }

        return DB::table($table)->where($column, $id)->exists()
            ? $id
            : null;
    }

    private function currentUserId(Request $request): int
    {
        return (int) (
            $request->user()->user_id
            ?? $request->user()->getKey()
            ?? 0
        );
    }
}
