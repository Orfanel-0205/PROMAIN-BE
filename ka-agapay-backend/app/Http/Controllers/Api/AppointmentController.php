<?php
// app/Http/Controllers/Api/AppointmentController.php

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
use Throwable;

class AppointmentController extends Controller
{
    private const ACTIVE_STATUSES = [
        'pending',
        'confirmed',
        'approved',
        'scheduled',
    ];

    private const TERMINAL_STATUSES = [
        'completed',
        'cancelled',
        'rejected',
    ];

    private const ADMIN_ALLOWED_STATUSES = [
        'pending',
        'confirmed',
        'approved',
        'scheduled',
        'completed',
        'cancelled',
        'rejected',
    ];

    private const CONSULTATION_TYPES = [
        'online',
        'onsite',
        'telemedicine',
    ];

    private const MAX_APPOINTMENTS_PER_SLOT = 3;

    private const CLINIC_START_TIME = '08:00';
    private const CLINIC_END_TIME = '17:00';

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
            ->latest('appointment_date')
            ->latest('appointment_time')
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
            ->latest('appointment_date')
            ->latest('appointment_time')
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
            'consultation_type' => ['nullable', Rule::in(self::CONSULTATION_TYPES)],

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

            'rhu_id' => ['nullable', 'integer', 'exists:barangays,barangay_id'],
            'urgency_level' => ['nullable', Rule::in(['routine', 'urgent', 'emergency'])],
        ]);

        $user = $request->user();

        $consultationType = $this->normalizeConsultationType(
            $validated['consultation_type'] ?? 'onsite'
        );

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

        $dateError = $this->validateScheduleDateTime($appointmentDate, $appointmentTime, false);

        if ($dateError) {
            return response()->json([
                'message' => $dateError,
                'errors' => [
                    'appointment_date' => [$dateError],
                ],
            ], 422);
        }

        $rhuId = $validated['rhu_id'] ?? $this->resolveRhuIdFromUser($user);

        if (!$rhuId) {
            return response()->json([
                'message' => 'RHU target could not be determined. Please seed barangays or update the user barangay.',
            ], 422);
        }

        $duplicate = Appointment::query()
            ->where('user_id', $user->user_id)
            ->whereDate('appointment_date', $appointmentDate)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => 'You already have an active appointment request for this date.',
                'appointment' => $duplicate->fresh(['resident', 'handler']),
            ], 409);
        }

        if ($appointmentTime && !$this->slotHasCapacity($appointmentDate, $appointmentTime, null)) {
            return response()->json([
                'message' => 'Selected appointment time is already full. Please choose another time.',
                'errors' => [
                    'appointment_time' => ['This time slot is already full.'],
                ],
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
                        'queue_ticket_id' => $queueTicket?->id,
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
            'status' => ['required', Rule::in(self::ADMIN_ALLOWED_STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($validated['status'] !== 'cancelled') {
            return response()->json([
                'message' => 'Patients can only cancel their own appointment.',
            ], 403);
        }

        if (in_array($appointment->status, self::TERMINAL_STATUSES, true)) {
            return response()->json([
                'message' => 'This appointment is already closed.',
            ], 422);
        }

        $appointment->update([
            'status' => 'cancelled',
            'notes' => $validated['notes'] ?? 'Cancelled by patient.',
            'rejection_reason' => 'Cancelled by patient.',
        ]);

        $this->cancelLinkedQueueTicket($appointment, 'Appointment cancelled by patient.');

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
            ->orderByRaw("
                CASE status
                    WHEN 'pending' THEN 1
                    WHEN 'approved' THEN 2
                    WHEN 'confirmed' THEN 3
                    WHEN 'scheduled' THEN 4
                    WHEN 'completed' THEN 5
                    WHEN 'cancelled' THEN 6
                    WHEN 'rejected' THEN 7
                    ELSE 8
                END
            ")
            ->orderBy('appointment_date')
            ->orderBy('appointment_time');

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('type') && $request->query('type') !== 'all') {
            $query->where('consultation_type', $request->query('type'));
        }

        if ($request->filled('date') && $request->query('date') !== 'all') {
            $query->whereDate('appointment_date', $request->query('date'));
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
            $request->integer('per_page', 50)
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

        if (
            Schema::hasTable('queue_tickets') &&
            Schema::hasColumn('queue_tickets', 'appointment_id')
        ) {
            $data['queue_ticket'] = QueueTicket::query()
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
        $appointment = Appointment::query()
            ->with(['resident', 'handler'])
            ->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in(self::ADMIN_ALLOWED_STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'rejection_reason' => ['nullable', 'string', 'max:5000'],
            'appointment_date' => ['nullable', 'date'],
            'appointment_time' => [
                'nullable',
                'regex:/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',
            ],
        ]);

        if (in_array($appointment->status, self::TERMINAL_STATUSES, true)) {
            return response()->json([
                'message' => 'This appointment is already closed and cannot be changed.',
            ], 422);
        }

        $status = $validated['status'];
        $targetDate = $validated['appointment_date'] ?? $appointment->appointment_date?->toDateString();
        $targetTime = array_key_exists('appointment_time', $validated)
            ? $this->normalizeAppointmentTime($validated['appointment_time'])
            : $this->normalizeAppointmentTime((string) $appointment->appointment_time);

        if (in_array($status, ['confirmed', 'approved', 'scheduled'], true)) {
            $dateError = $this->validateScheduleDateTime($targetDate, $targetTime, true);

            if ($dateError) {
                return response()->json([
                    'message' => $dateError,
                    'errors' => [
                        'appointment_date' => [$dateError],
                    ],
                ], 422);
            }

            if ($targetTime && !$this->slotHasCapacity($targetDate, $targetTime, $appointment->id)) {
                return response()->json([
                    'message' => 'Selected appointment time is already full. Please choose another time.',
                    'errors' => [
                        'appointment_time' => ['This time slot is already full.'],
                    ],
                ], 422);
            }
        }

        $updateData = [
            'status' => $status,
            'notes' => $validated['notes'] ?? $appointment->notes,
            'handled_by' => $request->user()->user_id,
        ];

        if (array_key_exists('appointment_date', $validated)) {
            $updateData['appointment_date'] = $targetDate;
        }

        if (array_key_exists('appointment_time', $validated)) {
            $updateData['appointment_time'] = $targetTime;
        }

        if (in_array($status, ['confirmed', 'approved'], true)) {
            $updateData['approved_at'] = $appointment->approved_at ?: now();
            $updateData['scheduled_at'] = $appointment->scheduled_at ?: now();
        }

        if ($status === 'scheduled') {
            $updateData['approved_at'] = $appointment->approved_at ?: now();
            $updateData['scheduled_at'] = now();
        }

        if ($status === 'rejected' || $status === 'cancelled') {
            $reason = $validated['rejection_reason']
                ?? $validated['notes']
                ?? ($status === 'rejected' ? 'Appointment was rejected.' : 'Appointment was cancelled.');

            $updateData['rejection_reason'] = $reason;
        }

        $appointment->update($updateData);

        if ($status === 'cancelled' || $status === 'rejected') {
            $this->cancelLinkedQueueTicket($appointment, $updateData['rejection_reason'] ?? 'Appointment closed.');
        }

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

        if (in_array($appointment->status, ['cancelled', 'rejected'], true)) {
            return response()->json([
                'message' => 'This appointment is closed and cannot be started.',
            ], 422);
        }

        if (!in_array($appointment->status, ['confirmed', 'approved', 'scheduled'], true)) {
            return response()->json([
                'message' => 'Only approved or scheduled appointments can be started.',
            ], 422);
        }

        if (!Schema::hasTable('consultations')) {
            return response()->json([
                'message' => 'Consultations table does not exist.',
            ], 500);
        }

        $existing = null;

        if (Schema::hasColumn('consultations', 'appointment_id')) {
            $existing = Consultation::query()
                ->where('appointment_id', $appointment->id)
                ->first();
        }

        if ($existing) {
            $appointment->update([
                'status' => 'completed',
                'handled_by' => $request->user()->user_id,
                'notes' => $appointment->notes ?: 'Consultation already started.',
            ]);

            return response()->json([
                'message' => 'Consultation already started.',
                'appointment' => $appointment->fresh(['resident', 'handler']),
                'consultation' => $existing,
            ]);
        }

        $consultationData = [
            'user_id' => $appointment->user_id,
            'attended_by' => $request->user()->user_id,
            'consultation_date' => now()->toDateString(),
            'chief_complaint' => $appointment->reason
                ?: $this->extractPurposeLine($appointment->purpose, 'Reason')
                ?: $appointment->purpose
                ?: 'Appointment consultation',
            'diagnosis' => null,
            'treatment' => null,
            'status' => 'open',
        ];

        if (Schema::hasColumn('consultations', 'appointment_id')) {
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

        if (Schema::hasColumn('consultations', 'notes')) {
            $consultationData['notes'] = 'Started from appointment #' . $appointment->id;
        }

        if (Schema::hasColumn('consultations', 'started_at')) {
            $consultationData['started_at'] = now();
        }

        $consultation = DB::transaction(function () use ($consultationData, $appointment, $request) {
            $consultation = Consultation::create($consultationData);

            $appointment->update([
                'status' => 'completed',
                'handled_by' => $request->user()->user_id,
                'notes' => $appointment->notes ?: 'Consultation started by staff.',
            ]);

            return $consultation;
        });

        return response()->json([
            'message' => 'Consultation started.',
            'appointment' => $appointment->fresh(['resident', 'handler']),
            'consultation' => $consultation,
        ], 201);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function normalizeConsultationType(?string $type): string
    {
        $type = strtolower(trim((string) ($type ?: 'onsite')));

        if ($type === 'telemedicine') {
            return 'online';
        }

        return $type === 'online' ? 'online' : 'onsite';
    }

    private function validateScheduleDateTime(?string $date, ?string $time, bool $adminAction): ?string
    {
        if (!$date) {
            return 'Please choose an appointment date.';
        }

        try {
            $day = Carbon::parse($date)->startOfDay();
        } catch (Throwable) {
            return 'Invalid appointment date.';
        }

        if (!$adminAction && $day->lt(today())) {
            return 'Appointment date cannot be in the past.';
        }

        if ($time) {
            $time = $this->normalizeAppointmentTime($time);

            if (!$time) {
                return 'Invalid appointment time.';
            }

            if ($time < self::CLINIC_START_TIME || $time > self::CLINIC_END_TIME) {
                return 'Appointment time must be within RHU clinic hours, 08:00 AM to 05:00 PM.';
            }

            $dateTime = Carbon::parse($day->toDateString() . ' ' . $time);

            if (!$adminAction && $dateTime->lt(now())) {
                return 'Appointment schedule cannot be in the past.';
            }
        }

        return null;
    }

    private function slotHasCapacity(?string $date, ?string $time, ?int $ignoreAppointmentId): bool
    {
        if (!$date || !$time) {
            return true;
        }

        $time = $this->normalizeAppointmentTime($time);

        if (!$time) {
            return true;
        }

        $query = Appointment::query()
            ->whereDate('appointment_date', $date)
            ->where('appointment_time', $time)
            ->whereIn('status', ['confirmed', 'approved', 'scheduled']);

        if ($ignoreAppointmentId) {
            $query->where('id', '!=', $ignoreAppointmentId);
        }

        return $query->count() < self::MAX_APPOINTMENTS_PER_SLOT;
    }

    private function createOnlineQueueTicket(
        ResidentProfile $residentProfile,
        Appointment $appointment,
        int $rhuId,
        string $urgencyLevel
    ): QueueTicket {
        $serviceType = 'opd_consultation';

        $existing = QueueTicket::query()
            ->where('appointment_id', $appointment->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $ticketQuery = QueueTicket::query()
            ->where('rhu_id', $rhuId)
            ->where('service_type', $serviceType)
            ->whereDate('issued_at', today());

        if (Schema::hasColumn('queue_tickets', 'deleted_at')) {
            $ticketQuery->whereNull('deleted_at');
        }

        $lastPosition = $ticketQuery->max('queue_position');
        $nextNumber = ((int) $lastPosition) + 1;

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

    private function cancelLinkedQueueTicket(Appointment $appointment, string $reason): void
    {
        try {
            if (!Schema::hasTable('queue_tickets') || !Schema::hasColumn('queue_tickets', 'appointment_id')) {
                return;
            }

            QueueTicket::query()
                ->where('appointment_id', $appointment->id)
                ->whereIn('status', ['waiting', 'called'])
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // Do not block appointment closing if queue sync fails.
        }
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
            ?? $residentProfile->getAttribute('birthdate')
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
        } catch (Throwable) {
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