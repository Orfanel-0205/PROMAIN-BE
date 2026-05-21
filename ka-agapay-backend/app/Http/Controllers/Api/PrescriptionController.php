<?php
// app/Http/Controllers/Api/PrescriptionController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prescription\IssuePrescriptionRequest;
use App\Http\Requests\Prescription\DispensePrescriptionRequest;
use App\Http\Requests\Prescription\VoidPrescriptionRequest;
use App\Http\Resources\Prescription\PrescriptionResource;
use App\Models\Prescription;
use App\Services\Prescription\PrescriptionService;
use App\Services\Audit\AuditActions;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrescriptionController extends Controller
{
    public function __construct(
        private readonly PrescriptionService $service,
        private readonly AuditService        $audit
    ) {}

    /**
     * GET /api/v1/prescriptions
     * Staff/admin — full list with filters.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin']),
            403
        );

        $request->validate([
            'resident_profile_id' => ['nullable', 'integer'],
            'status'              => ['nullable', 'in:' . implode(',', Prescription::STATUSES)],
            'rhu_id'              => ['nullable', 'integer'],
            'from'                => ['nullable', 'date'],
            'to'                  => ['nullable', 'date'],
            'controlled_only'     => ['nullable', 'boolean'],
            'per_page'            => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $prescriptions = Prescription::with([
                'residentProfile.user',
                'prescribedBy',
                'dispensedBy',
            ])
            ->when(fn() => $request->filled('resident_profile_id'),
                fn($q) => $q->forResident($request->integer('resident_profile_id')))
            ->when(fn() => $request->filled('status'),
                fn($q) => $q->where('status', $request->status))
            ->when(fn() => $request->filled('rhu_id'),
                fn($q) => $q->forRhu($request->integer('rhu_id')))
            ->when(fn() => $request->filled('from'),
                fn($q) => $q->where('prescription_date', '>=', $request->from))
            ->when(fn() => $request->filled('to'),
                fn($q) => $q->where('prescription_date', '<=', $request->to))
            ->when(fn() => $request->boolean('controlled_only'),
                fn($q) => $q->controlled())
            ->latest('prescription_date')
            ->paginate($request->integer('per_page', 20));

        return response()->json(
            PrescriptionResource::collection($prescriptions)->response()->getData()
        );
    }

    /**
     * POST /api/v1/prescriptions
     * MHO / doctor issues a new prescription.
     */
    public function store(IssuePrescriptionRequest $request): JsonResponse
    {
        $prescription = $this->service->issue($request->validated());

        return response()->json([
            'message' => 'Prescription issued successfully.',
            'data'    => new PrescriptionResource($prescription),
        ], 201);
    }

    /**
     * GET /api/v1/prescriptions/{prescription}
     * Full prescription detail.
     */
    public function show(Request $request, Prescription $prescription): JsonResponse
    {
        $this->authorizeView($request, $prescription);

        $prescription->load([
            'residentProfile.user',
            'residentProfile.barangay',
            'prescribedBy',
            'dispensedBy',
            'voidedBy',
            'consultation',
            'telemedicineSession',
            'dispensingLogs.dispensedBy',
        ]);

        // Log PHI access
        $this->audit->info(AuditActions::RECORD_VIEWED, 'prescription', [
            'subject'       => $prescription,
            'subject_label' => $prescription->getAuditLabel(),
        ]);

        return response()->json(['data' => new PrescriptionResource($prescription)]);
    }

    /**
     * GET /api/v1/prescriptions/mine
     * Resident sees their own prescriptions.
     */
    public function mine(Request $request): JsonResponse
    {
        $resident = $request->user()->residentProfile;
        abort_unless($resident, 404, 'No resident profile found.');

        $prescriptions = Prescription::with(['prescribedBy', 'dispensedBy'])
            ->forResident($resident->id)
            ->latest('prescription_date')
            ->paginate(15);

        return response()->json(
            PrescriptionResource::collection($prescriptions)->response()->getData()
        );
    }

    /**
     * POST /api/v1/prescriptions/{prescription}/dispense
     * Staff marks a prescription as dispensed.
     */
    public function dispense(
        DispensePrescriptionRequest $request,
        Prescription $prescription
    ): JsonResponse {
        $prescription = $this->service->dispense($prescription, $request->validated());

        return response()->json([
            'message' => 'Prescription dispensed successfully.',
            'data'    => new PrescriptionResource($prescription),
        ]);
    }

    /**
     * PATCH /api/v1/prescriptions/{prescription}/void
     * MHO voids a prescription with a reason.
     */
    public function void(VoidPrescriptionRequest $request, Prescription $prescription): JsonResponse
    {
        $prescription = $this->service->void($prescription, $request->void_reason);

        return response()->json([
            'message' => 'Prescription voided.',
            'data'    => new PrescriptionResource($prescription),
        ]);
    }

    /**
     * GET /api/v1/prescriptions/resident/{residentProfileId}
     * Admin/staff views all prescriptions for a specific resident.
     */
    public function forResident(Request $request, int $residentProfileId): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin']),
            403
        );

        $prescriptions = Prescription::with(['prescribedBy', 'dispensedBy'])
            ->forResident($residentProfileId)
            ->latest('prescription_date')
            ->paginate(20);

        return response()->json(
            PrescriptionResource::collection($prescriptions)->response()->getData()
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function authorizeView(Request $request, Prescription $prescription): void
    {
        $user = $request->user();

        if ($user->hasAnyRole(['mho', 'super_admin', 'staff_admin'])) {
            return;
        }

        // Resident can only see their own
        $residentId = $user->residentProfile?->id;
        abort_unless(
            $residentId === $prescription->resident_profile_id,
            403,
            'You do not have permission to view this prescription.'
        );
    }
}
