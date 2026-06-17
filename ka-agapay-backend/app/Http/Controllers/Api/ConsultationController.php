<?php
// app/Http/Controllers/Api/ConsultationController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ConsultationController extends Controller
{
    private const VALID_STATUSES = [
        'open',
        'ongoing',
        'completed',
        'cancelled',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Consultation::with(['resident', 'attendant', 'appointment'])
            ->latest('consultation_date')
            ->latest('id');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('chief_complaint', 'like', "%{$search}%")
                    ->orWhere('diagnosis', 'like', "%{$search}%")
                    ->orWhere('treatment', 'like', "%{$search}%");

                foreach (['subjective', 'objective', 'assessment', 'plan', 'notes'] as $column) {
                    if (Schema::hasColumn('consultations', $column)) {
                        $q->orWhere($column, 'like', "%{$search}%");
                    }
                }

                $q->orWhereHas('resident', function ($resident) use ($search) {
                    $resident->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('mobile_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });

                $q->orWhereHas('attendant', function ($staff) use ($search) {
                    $staff->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        return response()->json(
            $query->paginate($request->integer('per_page', 50))
        );
    }

    public function mine(Request $request): JsonResponse
    {
        $consultations = Consultation::with(['attendant', 'appointment'])
            ->where('user_id', $request->user()->user_id)
            ->latest('consultation_date')
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        $items = $consultations
            ->getCollection()
            ->map(fn (Consultation $consultation) => $this->formatForMobile($consultation));

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $consultations->currentPage(),
                'last_page' => $consultations->lastPage(),
                'per_page' => $consultations->perPage(),
                'total' => $consultations->total(),
                'from' => $consultations->firstItem() ?? 0,
                'to' => $consultations->lastItem() ?? 0,
                'path' => $request->url(),
            ],
            'links' => [
                'first' => $consultations->url(1),
                'last' => $consultations->url($consultations->lastPage()),
                'prev' => $consultations->previousPageUrl(),
                'next' => $consultations->nextPageUrl(),
            ],
        ]);
    }

    public function mineShow(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::with(['attendant', 'appointment'])
            ->where('user_id', $request->user()->user_id)
            ->findOrFail($id);

        return response()->json([
            'consultation' => $this->formatForMobile($consultation),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $relations = ['resident', 'attendant', 'appointment'];

        if (method_exists(Consultation::class, 'medicalReports')) {
            $relations[] = 'medicalReports';
        }

        $consultation = Consultation::with($relations)->findOrFail($id);

        return response()->json([
            'consultation' => $consultation,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'user_id' => ['required', 'exists:users,user_id'],
            'consultation_date' => ['nullable', 'date'],
            'chief_complaint' => ['nullable', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'treatment' => ['nullable', 'string'],
            'subjective' => ['nullable', 'string'],
            'objective' => ['nullable', 'string'],
            'assessment' => ['nullable', 'string'],
            'plan' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(self::VALID_STATUSES)],
        ]);

        $payload = $this->filterConsultationPayload([
            ...$validated,
            'attended_by' => $request->user()->user_id,
            'consultation_date' => $validated['consultation_date'] ?? now()->toDateString(),
            'status' => $validated['status'] ?? 'open',
            'started_at' => now(),
        ]);

        if (empty($payload['chief_complaint']) && !empty($payload['subjective'])) {
            $payload['chief_complaint'] = $payload['subjective'];
        }

        $consultation = Consultation::create($payload)
            ->load(['resident', 'attendant', 'appointment']);

        return response()->json([
            'message' => 'Consultation created.',
            'consultation' => $consultation,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->updateSoap($request, $id);
    }

    public function updateSoap(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::findOrFail($id);

        if ($consultation->status === 'completed') {
            return response()->json([
                'message' => 'This consultation is already completed and cannot be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'consultation_date' => ['nullable', 'date'],
            'chief_complaint' => ['nullable', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'treatment' => ['nullable', 'string'],
            'treatment_plan' => ['nullable', 'string'],
            'subjective' => ['nullable', 'string'],
            'objective' => ['nullable', 'string'],
            'assessment' => ['nullable', 'string'],
            'plan' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(self::VALID_STATUSES)],
        ]);

        $updates = $this->buildSoapUpdates($consultation, $validated, $request);

        $consultation->update($updates);

        if (($updates['status'] ?? null) === 'cancelled' && $consultation->appointment) {
            $consultation->appointment->update([
                'status' => 'cancelled',
                'handled_by' => $consultation->appointment->handled_by ?: $request->user()->user_id,
            ]);
        }

        return response()->json([
            'message' => 'SOAP note saved.',
            'consultation' => $consultation->fresh([
                'resident',
                'attendant',
                'appointment',
                'medicalReports',
            ]),
        ]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::with('appointment')->findOrFail($id);

        if ($consultation->status === 'completed') {
            return response()->json([
                'message' => 'This consultation is already completed.',
                'consultation' => $consultation->fresh([
                    'resident',
                    'attendant',
                    'appointment',
                    'medicalReports',
                ]),
            ]);
        }

        if ($request->all()) {
            $updates = $this->buildSoapUpdates($consultation, $request->all(), $request);
            unset($updates['status']);
            unset($updates['completed_at']);

            $consultation->update($updates);
            $consultation = Consultation::with('appointment')->findOrFail($id);
        }

        $subjective = trim((string) (
            $consultation->subjective
            ?: $consultation->chief_complaint
            ?: ''
        ));

        $assessment = trim((string) (
            $consultation->assessment
            ?: $consultation->diagnosis
            ?: ''
        ));

        $plan = trim((string) (
            $consultation->plan
            ?: $consultation->treatment
            ?: ''
        ));

        if ($subjective === '' || $assessment === '' || $plan === '') {
            return response()->json([
                'message' => 'Please complete the SOAP note before completing the consultation. Subjective, Assessment/Diagnosis, and Plan/Treatment are required.',
                'errors' => [
                    'soap' => [
                        'Subjective, Assessment/Diagnosis, and Plan/Treatment are required.',
                    ],
                ],
            ], 422);
        }

        $consultation->update($this->filterConsultationPayload([
            'status' => 'completed',
            'completed_at' => now(),
            'attended_by' => $consultation->attended_by ?: $request->user()->user_id,
            'diagnosis' => $consultation->diagnosis ?: $consultation->assessment,
            'treatment' => $consultation->treatment ?: $consultation->plan,
        ]));

        if ($consultation->appointment) {
            $consultation->appointment->update([
                'status' => 'completed',
                'handled_by' => $consultation->appointment->handled_by ?: $request->user()->user_id,
            ]);
        }

        return response()->json([
            'message' => 'Consultation completed.',
            'consultation' => $consultation->fresh([
                'resident',
                'attendant',
                'appointment',
                'medicalReports',
            ]),
        ]);
    }

    private function buildSoapUpdates(
        Consultation $consultation,
        array $validated,
        Request $request
    ): array {
        $updates = $validated;

        if (isset($updates['treatment_plan']) && !isset($updates['treatment'])) {
            $updates['treatment'] = $updates['treatment_plan'];
        }

        unset($updates['treatment_plan']);

        if (!empty($updates['subjective']) && empty($updates['chief_complaint'])) {
            $updates['chief_complaint'] = $updates['subjective'];
        }

        if (!empty($updates['assessment']) && empty($updates['diagnosis'])) {
            $updates['diagnosis'] = $updates['assessment'];
        }

        if (!empty($updates['plan']) && empty($updates['treatment'])) {
            $updates['treatment'] = $updates['plan'];
        }

        if (($updates['status'] ?? null) === 'completed') {
            $updates['completed_at'] = now();
        }

        if (!$consultation->started_at) {
            $updates['started_at'] = now();
        }

        if (!$consultation->attended_by) {
            $updates['attended_by'] = $request->user()->user_id;
        }

        if (
            empty($updates['status']) &&
            in_array($consultation->status, ['open', null, ''], true)
        ) {
            $updates['status'] = 'ongoing';
        }

        return $this->filterConsultationPayload($updates);
    }

    private function filterConsultationPayload(array $payload): array
    {
        $filtered = [];

        foreach ($payload as $key => $value) {
            if (Schema::hasColumn('consultations', $key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function formatForMobile(Consultation $consultation): array
    {
        $doctorName = $consultation->attendant?->full_name
            ?: ($consultation->attended_by ? 'Staff #' . $consultation->attended_by : 'RHU Staff');

        return [
            'id' => $consultation->id,
            'appointment_id' => $consultation->appointment_id,
            'user_id' => $consultation->user_id,
            'attended_by' => $consultation->attended_by,
            'doctor_name' => $doctorName,
            'specialty' => 'General Medicine',
            'date' => $consultation->consultation_date?->toDateString() ?? $consultation->created_at?->toDateString(),
            'consultation_date' => $consultation->consultation_date?->toDateString(),
            'chief_complaint' => $consultation->chief_complaint,
            'diagnosis' => $consultation->diagnosis,
            'treatment' => $consultation->treatment,
            'treatment_plan' => $consultation->treatment,
            'prescription' => $consultation->treatment,
            'status' => $consultation->status,
            'subjective' => $consultation->subjective,
            'objective' => $consultation->objective,
            'assessment' => $consultation->assessment,
            'plan' => $consultation->plan,
            'notes' => $consultation->notes,
            'started_at' => $consultation->started_at,
            'completed_at' => $consultation->completed_at,
            'created_at' => $consultation->created_at,
            'updated_at' => $consultation->updated_at,
            'attendant' => $consultation->attendant,
            'appointment' => $consultation->appointment,
        ];
    }
}