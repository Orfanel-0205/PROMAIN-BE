<?php
//app/Http/Controllers/Api/ConsultationController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConsultationController extends Controller
{
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
                    ->orWhere('treatment', 'like', "%{$search}%")
                    ->orWhere('subjective', 'like', "%{$search}%")
                    ->orWhere('objective', 'like', "%{$search}%")
                    ->orWhere('assessment', 'like', "%{$search}%")
                    ->orWhere('plan', 'like', "%{$search}%")
                    ->orWhereHas('resident', function ($resident) use ($search) {
                        $resident->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function mine(Request $request): JsonResponse
    {
        $consultations = Consultation::with(['attendant', 'appointment'])
            ->where('user_id', $request->user()->user_id)
            ->latest('consultation_date')
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        $items = $consultations->getCollection()->map(fn (Consultation $c) => $this->formatForMobile($c));

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
        $consultation = Consultation::with(['resident', 'attendant', 'appointment', 'medicalReports'])
            ->findOrFail($id);

        return response()->json(['consultation' => $consultation]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'user_id' => ['required', 'exists:users,user_id'],
            'consultation_date' => ['required', 'date'],
            'chief_complaint' => ['nullable', 'string'],
            'diagnosis' => ['nullable', 'string'],
            'treatment' => ['nullable', 'string'],
            'subjective' => ['nullable', 'string'],
            'objective' => ['nullable', 'string'],
            'assessment' => ['nullable', 'string'],
            'plan' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['open', 'ongoing', 'completed', 'cancelled'])],
        ]);

        $consultation = Consultation::create([
            ...$validated,
            'attended_by' => $request->user()->user_id,
            'status' => $validated['status'] ?? 'open',
            'started_at' => now(),
        ])->load(['resident', 'attendant', 'appointment']);

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
            'status' => ['nullable', Rule::in(['open', 'ongoing', 'completed', 'cancelled'])],
        ]);

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

        $consultation->update($updates);

        return response()->json([
            'message' => 'SOAP note saved.',
            'consultation' => $consultation->fresh(['resident', 'attendant', 'appointment', 'medicalReports']),
        ]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::with('appointment')->findOrFail($id);

        if ($request->all()) {
            $this->updateSoap($request, $id);
            $consultation = Consultation::with('appointment')->findOrFail($id);
        }

        $consultation->update([
            'status' => 'completed',
            'completed_at' => now(),
            'attended_by' => $consultation->attended_by ?: $request->user()->user_id,
        ]);

        if ($consultation->appointment) {
            $consultation->appointment->update([
                'status' => 'completed',
                'handled_by' => $consultation->appointment->handled_by ?: $request->user()->user_id,
            ]);
        }

        return response()->json([
            'message' => 'Consultation completed.',
            'consultation' => $consultation->fresh(['resident', 'attendant', 'appointment', 'medicalReports']),
        ]);
    }

    private function formatForMobile(Consultation $c): array
    {
        $doctorName = $c->attendant?->full_name
            ?: ($c->attended_by ? 'Staff #' . $c->attended_by : 'RHU Staff');

        return [
            'id' => $c->id,
            'appointment_id' => $c->appointment_id,
            'user_id' => $c->user_id,
            'attended_by' => $c->attended_by,
            'doctor_name' => $doctorName,
            'specialty' => 'General Medicine',
            'date' => $c->consultation_date?->toDateString() ?? $c->created_at?->toDateString(),
            'consultation_date' => $c->consultation_date?->toDateString(),
            'chief_complaint' => $c->chief_complaint,
            'diagnosis' => $c->diagnosis,
            'treatment' => $c->treatment,
            'treatment_plan' => $c->treatment,
            'prescription' => $c->treatment,
            'status' => $c->status,
            'subjective' => $c->subjective,
            'objective' => $c->objective,
            'assessment' => $c->assessment,
            'plan' => $c->plan,
            'notes' => $c->notes,
            'started_at' => $c->started_at,
            'completed_at' => $c->completed_at,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
            'attendant' => $c->attendant,
            'appointment' => $c->appointment,
        ];
    }
}
