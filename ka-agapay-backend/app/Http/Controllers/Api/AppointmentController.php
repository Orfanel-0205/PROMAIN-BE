<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    // =========================================================================
    // MOBILE / PATIENT SIDE
    // =========================================================================

    /**
     * GET /api/v1/appointments
     * For mobile patient app, return only logged-in user's appointments.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->myAppointments($request);
    }

    /**
     * GET /api/v1/appointments/my
     */
    public function myAppointments(Request $request): JsonResponse
    {
        $appointments = Appointment::query()
            ->where('user_id', $request->user()->user_id)
            ->with(['resident', 'handler'])
            ->latest()
            ->paginate(15);

        return response()->json($appointments);
    }

    /**
     * GET /api/v1/appointments/{userId}
     */
    public function userAppointments(Request $request, string $userId): JsonResponse
    {
        if ((string) $request->user()->user_id !== (string) $userId) {
            return response()->json([
                'message' => 'Forbidden. You can only view your own appointments.',
            ], 403);
        }

        $appointments = Appointment::query()
            ->where('user_id', $request->user()->user_id)
            ->with(['resident', 'handler'])
            ->latest()
            ->get();

        return response()->json($appointments);
    }

    /**
     * GET /api/v1/appointments/show/{id}
     * GET /api/v1/appointments/detail/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->user_id)
            ->with(['resident', 'handler'])
            ->firstOrFail();

        return response()->json($appointment);
    }

    /**
     * POST /api/v1/appointments
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'consultation_type' => ['nullable', Rule::in(['online', 'onsite'])],

            'reason' => ['nullable', 'string', 'max:500'],
            'symptoms' => ['nullable', 'string', 'max:5000'],
            'preferred_date' => ['nullable', 'date'],
            'preferred_time' => ['nullable', 'date_format:H:i'],

            'appointment_date' => ['nullable', 'date'],
            'appointment_time' => ['nullable', 'date_format:H:i'],
            'purpose' => ['nullable', 'string', 'max:5000'],
        ]);

        $consultationType = $validated['consultation_type'] ?? 'onsite';

        $appointmentDate = $validated['appointment_date']
            ?? $validated['preferred_date']
            ?? null;

        $appointmentTime = $validated['appointment_time']
            ?? $validated['preferred_time']
            ?? null;

        $reason = trim((string) ($validated['reason'] ?? ''));
        $symptoms = trim((string) ($validated['symptoms'] ?? ''));

        $purpose = $validated['purpose'] ?? null;

        if (!$purpose) {
            $purpose = collect([
                '[' . strtoupper($consultationType) . ' CONSULTATION]',
                $reason ? 'Reason: ' . $reason : null,
                $symptoms ? 'Symptoms: ' . $symptoms : null,
            ])->filter()->implode("\n");
        }

        if (!$appointmentDate) {
            return response()->json([
                'message' => 'The appointment date field is required.',
                'errors' => [
                    'appointment_date' => ['Please choose your preferred appointment date.'],
                ],
            ], 422);
        }

        if (!$reason && !$purpose) {
            return response()->json([
                'message' => 'The reason field is required.',
                'errors' => [
                    'reason' => ['Please enter your reason for consultation.'],
                ],
            ], 422);
        }

        $appointment = Appointment::create([
            'user_id' => $request->user()->user_id,
            'handled_by' => null,
            'appointment_date' => $appointmentDate,
            'appointment_time' => $appointmentTime,
            'purpose' => $purpose,
            'status' => 'pending',
            'notes' => null,

            'consultation_type' => $consultationType,
            'reason' => $reason ?: null,
            'symptoms' => $symptoms ?: null,

            'rejection_reason' => null,
            'approved_at' => null,
            'scheduled_at' => null,
        ]);

        return response()->json([
            'message' => 'Appointment request submitted.',
            'appointment' => $appointment->fresh(['resident', 'handler']),
        ], 201);
    }

    /**
     * PATCH /api/v1/appointments/{id}/status
     * Patient can only cancel their own appointment.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->user_id)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                'pending',
                'confirmed',
                'approved',
                'scheduled',
                'completed',
                'cancelled',
                'rejected',
            ])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($validated['status'] !== 'cancelled') {
            return response()->json([
                'message' => 'Patients can only cancel their own appointment.',
            ], 403);
        }

        $appointment->update([
            'status' => 'cancelled',
            'notes' => $validated['notes'] ?? 'Cancelled by patient.',
        ]);

        return response()->json([
            'message' => 'Appointment cancelled.',
            'appointment' => $appointment->fresh(['resident', 'handler']),
        ]);
    }

    // =========================================================================
    // WEB ADMIN SIDE
    // =========================================================================

    /**
     * GET /api/v1/admin/appointments
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = Appointment::query()
            ->with(['resident', 'handler'])
            ->latest();

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('type') && $request->query('type') !== 'all') {
            $query->where('consultation_type', $request->query('type'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));

            $query->where(function ($q) use ($search) {
                $q->where('purpose', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('symptoms', 'like', "%{$search}%")
                    ->orWhereHas('resident', function ($userQuery) use ($search) {
                        $userQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $appointments = $query->paginate(
            $request->integer('per_page', 20)
        );

        return response()->json($appointments);
    }

    /**
     * GET /api/v1/admin/appointments/{id}
     */
    public function adminShow(int $id): JsonResponse
    {
        $appointment = Appointment::query()
            ->with(['resident', 'handler'])
            ->findOrFail($id);

        $data = $appointment->toArray();

        if (
            Schema::hasTable('consultations') &&
            Schema::hasColumn('consultations', 'appointment_id')
        ) {
            $data['consultation'] = Consultation::query()
                ->where('appointment_id', $appointment->id)
                ->latest()
                ->first();
        }

        return response()->json([
            'appointment' => $data,
        ]);
    }

    /**
     * PATCH /api/v1/admin/appointments/{id}/status
     */
    public function adminUpdateStatus(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::query()->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                'pending',
                'confirmed',
                'approved',
                'scheduled',
                'completed',
                'cancelled',
                'rejected',
            ])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'rejection_reason' => ['nullable', 'string', 'max:5000'],
            'appointment_date' => ['nullable', 'date'],
            'appointment_time' => ['nullable', 'date_format:H:i'],
        ]);

        $status = $validated['status'];

        $updateData = [
            'status' => $status,
            'notes' => $validated['notes'] ?? $appointment->notes,
            'handled_by' => $request->user()->user_id,
        ];

        if (array_key_exists('appointment_date', $validated)) {
            $updateData['appointment_date'] = $validated['appointment_date'];
        }

        if (array_key_exists('appointment_time', $validated)) {
            $updateData['appointment_time'] = $validated['appointment_time'];
        }

        if (in_array($status, ['confirmed', 'approved'], true)) {
            $updateData['approved_at'] = now();
            $updateData['scheduled_at'] = now();
        }

        if ($status === 'scheduled') {
            $updateData['scheduled_at'] = now();
        }

        if ($status === 'rejected' || $status === 'cancelled') {
            $updateData['rejection_reason'] = $validated['rejection_reason']
                ?? $validated['notes']
                ?? 'Appointment was rejected.';
        }

        $appointment->update($updateData);

        return response()->json([
            'message' => 'Appointment status updated.',
            'appointment' => $appointment->fresh(['resident', 'handler']),
        ]);
    }

    /**
     * POST /api/v1/admin/appointments/{id}/start-consultation
     */
    public function startConsultationFromAppointment(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::query()
            ->with(['resident', 'handler'])
            ->findOrFail($id);

        if (!Schema::hasTable('consultations')) {
            return response()->json([
                'message' => 'Consultations table does not exist.',
            ], 500);
        }

        $consultationData = [
            'user_id' => $appointment->user_id,
            'attended_by' => $request->user()->user_id,
            'consultation_date' => now()->toDateString(),
            'chief_complaint' => $appointment->reason
                ?: $this->extractPurposeLine($appointment->purpose, 'Reason')
                ?: $appointment->purpose,
            'diagnosis' => null,
            'treatment' => null,
            'status' => 'open',
        ];

        if (Schema::hasColumn('consultations', 'appointment_id')) {
            $existing = Consultation::query()
                ->where('appointment_id', $appointment->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Consultation already started.',
                    'consultation' => $existing,
                    'appointment' => $appointment,
                ]);
            }

            $consultationData['appointment_id'] = $appointment->id;
        }

        if (Schema::hasColumn('consultations', 'subjective')) {
            $consultationData['subjective'] = $appointment->reason
                ?: $this->extractPurposeLine($appointment->purpose, 'Reason')
                ?: $appointment->purpose;
        }

        if (Schema::hasColumn('consultations', 'objective')) {
            $consultationData['objective'] = $appointment->symptoms
                ?: $this->extractPurposeLine($appointment->purpose, 'Symptoms');
        }

        if (Schema::hasColumn('consultations', 'started_at')) {
            $consultationData['started_at'] = now();
        }

        $consultation = Consultation::create($consultationData);

        $appointment->update([
            'status' => 'completed',
            'handled_by' => $request->user()->user_id,
            'notes' => $appointment->notes ?: 'Consultation started by staff.',
        ]);

        return response()->json([
            'message' => 'Consultation started.',
            'appointment' => $appointment->fresh(['resident', 'handler']),
            'consultation' => $consultation,
        ], 201);
    }

    private function extractPurposeLine(?string $purpose, string $label): ?string
    {
        if (!$purpose) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $purpose);

        foreach ($lines as $line) {
            $line = trim($line);

            if (stripos($line, $label . ':') === 0) {
                return trim(substr($line, strlen($label) + 1));
            }
        }

        return null;
    }
}