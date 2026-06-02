<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Barangay;
use App\Models\Consultation;
use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use App\Models\TelemedicineRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    // =========================================================================
    // MOBILE / PATIENT SIDE
    // =========================================================================

    /**
     * GET /api/v1/appointments
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
            'consultation_type' => ['nullable', Rule::in(['online', 'onsite', 'telemedicine'])],

            'reason' => ['nullable', 'string', 'max:500'],
            'symptoms' => ['nullable', 'string', 'max:5000'],
            'preferred_date' => ['nullable', 'date'],

            'preferred_time' => [
                'nullable',
                'regex:/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
            ],

            'appointment_date' => ['nullable', 'date'],

            'appointment_time' => [
                'nullable',
                'regex:/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
            ],

            'purpose' => ['nullable', 'string', 'max:5000'],

            // Optional. If mobile does not send this, backend will auto-resolve it.
            'rhu_id' => ['nullable', 'integer', 'exists:barangays,barangay_id'],

            // Optional for online triage.
            'urgency_level' => ['nullable', Rule::in(['routine', 'urgent', 'emergency'])],
        ]);

        $user = $request->user();

        $consultationType = $validated['consultation_type'] ?? 'onsite';

        // Accept "telemedicine" from frontend, but save as "online" in DB.
        if ($consultationType === 'telemedicine') {
            $consultationType = 'online';
        }

        $appointmentDate = $validated['appointment_date']
            ?? $validated['preferred_date']
            ?? null;

        $appointmentTime = $this->normalizeAppointmentTime(
            $validated['appointment_time']
                ?? $validated['preferred_time']
                ?? null
        );

        $reason = trim((string) ($validated['reason'] ?? ''));
        $symptoms = trim((string) ($validated['symptoms'] ?? ''));
        $urgencyLevel = $validated['urgency_level'] ?? 'routine';

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

        if (!$reason && !$symptoms && !$purpose) {
            return response()->json([
                'message' => 'The reason field is required.',
                'errors' => [
                    'reason' => ['Please enter your reason for consultation.'],
                ],
            ], 422);
        }

        $rhuId = $validated['rhu_id']
            ?? $this->resolveRhuIdFromUser($user);

        if (!$rhuId) {
            return response()->json([
                'message' => 'RHU target could not be determined. Please seed barangays or update the user barangay.',
            ], 422);
        }

        $result = DB::transaction(function () use (
            $user,
            $consultationType,
            $appointmentDate,
            $appointmentTime,
            $purpose,
            $reason,
            $symptoms,
            $urgencyLevel,
            $rhuId
        ) {
            $residentProfile = $this->ensureResidentProfile($user, (int) $rhuId);

            $appointment = Appointment::create([
                'user_id' => $user->user_id,
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

            $queueTicket = null;
            $telemedicineRequest = null;

            if ($consultationType === 'online') {
                $queueTicket = $this->createOnlineQueueTicket(
                    residentProfile: $residentProfile,
                    appointment: $appointment,
                    rhuId: (int) $rhuId,
                    urgencyLevel: $urgencyLevel
                );

                $symptomList = collect(preg_split('/[,;\n]+/', $symptoms))
                    ->map(fn ($item) => trim((string) $item))
                    ->filter()
                    ->values()
                    ->all();

                if (Schema::hasTable('telemedicine_requests')) {
                    $telemedicineRequest = TelemedicineRequest::create([
                        'resident_profile_id' => $residentProfile->id,
                        'requested_by' => $user->user_id,
                        'queue_ticket_id' => $queueTicket->id,
                        'appointment_id' => $appointment->id,
                        'rhu_id' => (int) $rhuId,

                        'endorsed_by_bhw' => null,
                        'is_bhw_assisted' => false,
                        'bhw_notes' => null,

                        'chief_complaint' => $reason ?: $purpose,
                        'urgency_level' => $urgencyLevel,
                        'symptoms' => $symptomList,
                        'additional_notes' => $symptoms ?: null,

                        'screened_by' => null,
                        'screening_notes' => null,
                        'screened_at' => null,

                        'status' => 'pending',
                        'rejection_reason' => null,
                        'cancellation_reason' => null,
                        'cancelled_at' => null,
                    ]);
                }
            }

            return [
                'appointment' => $appointment->fresh(['resident', 'handler']),
                'queue_ticket' => $queueTicket?->fresh(),
                'telemedicine_request' => $telemedicineRequest?->fresh([
                    'residentProfile.user',
                    'rhu',
                    'queueTicket',
                ]),
            ];
        });

        return response()->json([
            'message' => $consultationType === 'online'
                ? 'Online consultation request submitted. Please wait for RHU screening.'
                : 'Appointment request submitted.',
            'appointment' => $result['appointment'],
            'queue_ticket' => $result['queue_ticket'],
            'telemedicine_request' => $result['telemedicine_request'],
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
            'appointment_time' => [
                'nullable',
                'regex:/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
            ],
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
            $updateData['appointment_time'] = $this->normalizeAppointmentTime(
                $validated['appointment_time']
            );
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

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function createOnlineQueueTicket(
        ResidentProfile $residentProfile,
        Appointment $appointment,
        int $rhuId,
        string $urgencyLevel
    ): QueueTicket {
        $serviceType = 'opd_consultation';

        /*
         * PostgreSQL does not allow lockForUpdate() with count().
         * So we get the latest queue_position instead.
         */
        $ticketQuery = QueueTicket::query()
            ->where('rhu_id', $rhuId)
            ->where('service_type', $serviceType)
            ->whereDate('issued_at', today());

        if (Schema::hasColumn('queue_tickets', 'deleted_at')) {
            $ticketQuery->whereNull('deleted_at');
        }

        $lastPosition = $ticketQuery->max('queue_position');
        $nextNumber = ((int) $lastPosition) + 1;

        /*
         * Example: R1-OPD-260603-0001
         */
        $ticketNumber = 'R' . $rhuId
            . '-OPD-'
            . now()->format('ymd')
            . '-'
            . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);

        $age = $this->getResidentAge($residentProfile);

        $isSenior = $age !== null && $age >= 60;
        $isPediatric = $age !== null && $age < 5;
        $isEmergency = $urgencyLevel === 'emergency';

        $priorityScore = 0;

        if ($isEmergency) {
            $priorityScore += 100;
        }

        if ($isSenior) {
            $priorityScore += 30;
        }

        if ($isPediatric) {
            $priorityScore += 20;
        }

        $priorityCategory = $isEmergency
            ? 'emergency'
            : ($isSenior ? 'senior_citizen' : ($isPediatric ? 'pediatric' : 'regular'));

        $data = [
            'ticket_number' => $ticketNumber,
            'resident_profile_id' => $residentProfile->id,
            'appointment_id' => $appointment->id,
            'rhu_id' => $rhuId,
            'issued_by' => $appointment->user_id,
            'served_by' => null,
            'service_type' => $serviceType,
            'priority_score' => $priorityScore,
            'priority_category' => $priorityCategory,

            'is_senior' => $isSenior,
            'is_pregnant' => false,
            'is_pwd' => false,
            'is_pediatric' => $isPediatric,
            'is_emergency' => $isEmergency,
            'is_bhw_endorsed' => false,

            'status' => 'waiting',
            'queue_position' => $nextNumber,
            'call_attempt' => 0,
            'issued_at' => now(),
            'notes' => 'Online consultation request from appointment booking.',
        ];

        if (Schema::hasColumn('queue_tickets', 'queue_type')) {
            $data['queue_type'] = 'online';
        }

        return QueueTicket::create($data);
    }

    private function resolveRhuIdFromUser(User $user): ?int
    {
        $existingProfile = ResidentProfile::where('user_id', $user->user_id)->first();

        if ($existingProfile && $existingProfile->barangay_id) {
            return (int) $existingProfile->barangay_id;
        }

        if (!empty($user->barangay) && is_numeric($user->barangay)) {
            return (int) $user->barangay;
        }

        $firstBarangayId = Barangay::query()
            ->orderBy('barangay_id')
            ->value('barangay_id');

        return $firstBarangayId ? (int) $firstBarangayId : null;
    }

    private function ensureResidentProfile(User $user, int $rhuId): ResidentProfile
    {
        $profile = ResidentProfile::where('user_id', $user->user_id)->first();

        if ($profile) {
            if (!$profile->barangay_id) {
                $profile->update([
                    'barangay_id' => $rhuId,
                ]);
            }

            return $profile->refresh();
        }

        return ResidentProfile::create([
            'user_id' => $user->user_id,
            'barangay_id' => $rhuId,
            'birth_date' => $user->birthday,
            'sex' => $user->sex,
            'address' => $user->barangay,
            'philhealth_no' => null,
        ]);
    }

    private function getResidentAge(ResidentProfile $residentProfile): ?int
    {
        $birthDate = $residentProfile->getAttribute('birth_date')
            ?? $residentProfile->getAttribute('birthday')
            ?? $residentProfile->getAttribute('date_of_birth');

        if (!$birthDate && $residentProfile->relationLoaded('user') && $residentProfile->user) {
            $birthDate = $residentProfile->user->birthday
                ?? $residentProfile->user->birth_date
                ?? null;
        }

        if (!$birthDate) {
            return null;
        }

        try {
            return Carbon::parse($birthDate)->age;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeAppointmentTime(?string $time): ?string
    {
        if (!$time) {
            return null;
        }

        $time = trim($time);

        if (preg_match('/^(\d{1,2}):([0-5]\d)(?::[0-5]\d)?$/', $time, $matches)) {
            return str_pad((string) ((int) $matches[1]), 2, '0', STR_PAD_LEFT)
                . ':'
                . $matches[2];
        }

        return null;
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