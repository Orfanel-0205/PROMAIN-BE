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
use App\Models\TelemedicineSession;
use App\Models\User;
use App\Services\Queue\QueueService;
use App\Services\Telemedicine\WebRtcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class AppointmentController extends Controller
{
    private const ACTIVE_STATUSES = [
        'pending',
        'confirmed',
        'approved',
        'scheduled',
        'ongoing',
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
        'ongoing',
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
            ->with([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket',
                'telemedicineRequest.session',
            ])
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
            ->with([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket',
                'telemedicineRequest.session',
            ])
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
            ->with([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket',
                'telemedicineRequest.session',
            ])
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

        if (!$rhuId || !Barangay::query()->where('barangay_id', $rhuId)->exists()) {
            $rhuId = Barangay::query()->orderBy('barangay_id')->value('barangay_id');
        }

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
                'appointment' => $duplicate->fresh([
                    'resident',
                    'handler',
                    'rhu',
                    'consultation',
                    'queueTicket',
                    'telemedicineRequest.session',
                ]),
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
                'rhu_id' => (int) $rhuId,
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

            if ($consultationType === 'online' && Schema::hasTable('telemedicine_requests')) {
                $telemedicineRequest = TelemedicineRequest::create([
                    'resident_profile_id' => $residentProfile->id,
                    'requested_by' => $user->user_id,
                    'queue_ticket_id' => null,
                    'appointment_id' => $appointment->id,
                    'rhu_id' => (int) $rhuId,

                    'endorsed_by_bhw' => null,
                    'is_bhw_assisted' => false,
                    'bhw_notes' => null,

                    'chief_complaint' => $reason ?: $purpose,
                    'urgency_level' => $urgencyLevel,
                    'symptoms' => $this->parseSymptoms($symptoms),
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

            return [
                'appointment' => $appointment->fresh([
                    'resident',
                    'handler',
                    'rhu',
                    'consultation',
                    'queueTicket',
                    'telemedicineRequest.session',
                ]),
                'queue_ticket' => $queueTicket?->fresh(),
                'telemedicine_request' => $telemedicineRequest?->fresh([
                    'residentProfile.user',
                    'residentProfile.barangay',
                    'rhu',
                    'queueTicket',
                    'session',
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
            'appointment' => $appointment->fresh([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket',
                'telemedicineRequest.session',
            ]),
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
            ->with([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket',
                'telemedicineRequest.session',
            ])
            ->orderByRaw("
                CASE status
                    WHEN 'pending' THEN 1
                    WHEN 'approved' THEN 2
                    WHEN 'confirmed' THEN 3
                    WHEN 'scheduled' THEN 4
                    WHEN 'ongoing' THEN 5
                    WHEN 'completed' THEN 6
                    WHEN 'cancelled' THEN 7
                    WHEN 'rejected' THEN 8
                    ELSE 9
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
            ->with([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket',
                'telemedicineRequest.session',
            ])
            ->findOrFail($id);

        return response()->json([
            'appointment' => $appointment,
        ]);
    }

    /**
     * PATCH /api/v1/admin/appointments/{id}/status
     */
    public function adminUpdateStatus(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::query()
            ->with([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket',
                'telemedicineRequest.session',
            ])
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
            'handled_by' => $this->currentUserId($request),
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
            'appointment' => $appointment->fresh([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket',
                'telemedicineRequest.session',
            ]),
        ]);
    }

    /**
     * POST /api/v1/admin/appointments/{id}/add-to-queue
     *
     * Adds an approved/scheduled onsite appointment into the RHU queue.
     * This is intentionally separate from online telemedicine appointments.
     */
    public function addToQueueFromAppointment(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::query()
            ->with([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket',
                'telemedicineRequest.session',
            ])
            ->find($id);

        if (!$appointment) {
            return response()->json([
                'message' => 'Appointment not found.',
            ], 404);
        }

        $status = strtolower(trim((string) $appointment->status));
        $consultationType = $this->normalizeConsultationType(
            $appointment->consultation_type ?? null
        );

        if ($consultationType === 'online') {
            return response()->json([
                'message' => 'Online appointments do not require onsite queue tickets. Open telemedicine instead.',
            ], 422);
        }

        if ($status === 'pending') {
            return response()->json([
                'message' => 'Approve or schedule this appointment before adding it to the queue.',
            ], 422);
        }

        if (in_array($status, ['cancelled', 'rejected', 'completed'], true)) {
            return response()->json([
                'message' => 'Closed appointments cannot be added to the queue.',
            ], 422);
        }

        if (!in_array($status, ['confirmed', 'approved', 'scheduled', 'ongoing'], true)) {
            return response()->json([
                'message' => 'This appointment status cannot be added to the queue.',
            ], 422);
        }

        try {
            $ticket = DB::transaction(function () use ($appointment) {
                $existingTicket = QueueTicket::query()
                    ->where('appointment_id', $appointment->id)
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if ($existingTicket) {
                    return $existingTicket->fresh([
                        'residentProfile.barangay',
                        'rhu',
                        'issuedBy',
                        'servedBy',
                    ]);
                }

                return app(QueueService::class)->syncAppointmentToQueue($appointment);
            });

            if (!$ticket) {
                return response()->json([
                    'message' => 'Queue ticket could not be created. Please check the patient profile and RHU assignment.',
                ], 422);
            }

            $freshAppointment = $appointment->fresh([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket.residentProfile.barangay',
                'queueTicket.rhu',
                'queueTicket.issuedBy',
                'queueTicket.servedBy',
                'telemedicineRequest.session',
            ]);

            return response()->json([
                'message' => 'Patient added to queue.',
                'appointment' => $freshAppointment,
                'queue_ticket' => $ticket,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to add patient to queue. Please try again or contact the system administrator.',
            ], 500);
        }
    }

    /**
     * POST /api/v1/admin/appointments/{id}/start-consultation
     *
     * Permanent mapping:
     * - onsite appointment -> normal consultation/SOAP flow
     * - online appointment -> telemedicine request/session/room flow
     */
    public function startConsultationFromAppointment(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::query()
            ->with([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket',
                'telemedicineRequest.session',
            ])
            ->findOrFail($id);

        if (in_array($appointment->status, ['cancelled', 'rejected'], true)) {
            return response()->json([
                'message' => 'This appointment is closed and cannot be started.',
            ], 422);
        }

        if (!in_array($appointment->status, ['confirmed', 'approved', 'scheduled', 'ongoing'], true)) {
            return response()->json([
                'message' => 'Only approved, scheduled, or ongoing appointments can be opened.',
            ], 422);
        }

        if ($this->isOnlineAppointment($appointment)) {
            return $this->openTelemedicineFromAppointment($request, $appointment);
        }

        return $this->startOnsiteConsultationFromAppointment($request, $appointment);
    }

    private function startOnsiteConsultationFromAppointment(
        Request $request,
        Appointment $appointment
    ): JsonResponse {
        if (!Schema::hasTable('consultations')) {
            return response()->json([
                'message' => 'Consultation records are not available right now.',
            ], 500);
        }

        $result = DB::transaction(function () use ($request, $appointment) {
            $consultation = $this->createOrReuseConsultation(
                appointment: $appointment,
                staffId: $this->currentUserId($request),
                status: 'ongoing',
                note: 'Started from onsite appointment #' . $appointment->id
            );

            if ($appointment->status !== 'completed') {
                $appointment->update([
                    'status' => 'ongoing',
                    'handled_by' => $this->currentUserId($request),
                    'notes' => $appointment->notes ?: 'Consultation started by staff.',
                ]);
            }

            return [
                'appointment' => $appointment->fresh([
                    'resident',
                    'handler',
                    'rhu',
                    'consultation',
                    'queueTicket',
                    'telemedicineRequest.session',
                ]),
                'consultation' => $consultation->fresh(),
            ];
        });

        return response()->json([
            'message' => 'Consultation started.',
            'mode' => 'onsite',
            'appointment' => $result['appointment'],
            'consultation' => $result['consultation'],
            'redirect_to' => 'consultation',
        ], 201);
    }

    private function openTelemedicineFromAppointment(
        Request $request,
        Appointment $appointment
    ): JsonResponse {
        if (!Schema::hasTable('consultations')) {
            return response()->json([
                'message' => 'Consultation records are not available right now.',
            ], 500);
        }

        if (!Schema::hasTable('telemedicine_requests') || !Schema::hasTable('telemedicine_sessions')) {
            return response()->json([
                'message' => 'Telemedicine records are not available right now.',
            ], 500);
        }

        $staffId = $this->currentUserId($request);

        $result = DB::transaction(function () use ($appointment, $staffId) {
            $residentProfile = ResidentProfile::query()
                ->where('user_id', $appointment->user_id)
                ->first();

            if (!$residentProfile) {
                $residentUser = User::query()
                    ->where('user_id', $appointment->user_id)
                    ->first();

                if (!$residentUser) {
                    abort(422, 'Resident profile could not be found for this appointment.');
                }

                $residentProfile = $this->ensureResidentProfile(
                    $residentUser,
                    (int) ($appointment->rhu_id ?: $this->resolveRhuIdFromUser($residentUser) ?: 1)
                );
            }

            $consultation = $this->createOrReuseConsultation(
                appointment: $appointment,
                staffId: $staffId,
                status: 'ongoing',
                note: 'Started from online telemedicine appointment #' . $appointment->id
            );

            if ($consultation->status === 'completed') {
                return [
                    'history' => true,
                    'appointment' => $appointment->fresh([
                        'resident',
                        'handler',
                        'rhu',
                        'consultation',
                        'queueTicket',
                        'telemedicineRequest.session',
                    ]),
                    'consultation' => $consultation->fresh(),
                    'telemedicine_request' => $appointment->telemedicineRequest,
                    'telemedicine_session' => $appointment->telemedicineRequest?->session,
                    'room' => null,
                ];
            }

            $queueTicketId = $appointment->queueTicket?->id;

            $telemedicineRequest = TelemedicineRequest::query()
                ->where('appointment_id', $appointment->id)
                ->first();

            if (!$telemedicineRequest) {
                $telemedicineRequest = TelemedicineRequest::create([
                    'resident_profile_id' => $residentProfile->id,
                    'requested_by' => $appointment->user_id,
                    'queue_ticket_id' => $queueTicketId,
                    'appointment_id' => $appointment->id,
                    'rhu_id' => (int) ($appointment->rhu_id ?: $residentProfile->barangay_id ?: 1),

                    'endorsed_by_bhw' => null,
                    'is_bhw_assisted' => false,
                    'bhw_notes' => null,

                    'chief_complaint' => $appointment->reason
                        ?: $this->extractPurposeLine($appointment->purpose, 'Reason')
                        ?: $appointment->purpose
                        ?: 'Online consultation',
                    'urgency_level' => 'routine',
                    'symptoms' => $this->parseSymptoms(
                        $appointment->symptoms
                            ?: $this->extractPurposeLine($appointment->purpose, 'Symptoms')
                            ?: ''
                    ),
                    'additional_notes' => $appointment->symptoms ?: null,

                    'screened_by' => $staffId,
                    'screening_notes' => 'Opened directly from approved online appointment.',
                    'screened_at' => now(),

                    'status' => 'scheduled',
                    'rejection_reason' => null,
                    'cancellation_reason' => null,
                    'cancelled_at' => null,
                ]);
            } else {
                if (in_array($telemedicineRequest->status, ['rejected', 'cancelled'], true)) {
                    abort(422, 'This telemedicine request is already closed.');
                }

                if ($telemedicineRequest->status !== 'completed') {
                    $telemedicineRequest->update([
                        'resident_profile_id' => $telemedicineRequest->resident_profile_id ?: $residentProfile->id,
                        'queue_ticket_id' => $telemedicineRequest->queue_ticket_id ?: $queueTicketId,
                        'rhu_id' => $telemedicineRequest->rhu_id ?: (int) ($appointment->rhu_id ?: $residentProfile->barangay_id ?: 1),
                        'screened_by' => $telemedicineRequest->screened_by ?: $staffId,
                        'screening_notes' => $telemedicineRequest->screening_notes ?: 'Opened directly from approved online appointment.',
                        'screened_at' => $telemedicineRequest->screened_at ?: now(),
                        'status' => in_array($telemedicineRequest->status, ['pending', 'screened'], true)
                            ? 'scheduled'
                            : $telemedicineRequest->status,
                    ]);
                }
            }

            $session = TelemedicineSession::query()
                ->where('request_id', $telemedicineRequest->id)
                ->first();

            if ($session && in_array($session->status, ['ended', 'no_show', 'cancelled'], true)) {
                return [
                    'history' => true,
                    'appointment' => $appointment->fresh([
                        'resident',
                        'handler',
                        'rhu',
                        'consultation',
                        'queueTicket',
                        'telemedicineRequest.session',
                    ]),
                    'consultation' => $consultation->fresh(),
                    'telemedicine_request' => $telemedicineRequest->fresh([
                        'residentProfile.user',
                        'residentProfile.barangay',
                        'rhu',
                        'queueTicket',
                        'session',
                    ]),
                    'telemedicine_session' => $session->fresh(),
                    'room' => null,
                ];
            }

            if (!$session) {
                $session = TelemedicineSession::create([
                    'request_id' => $telemedicineRequest->id,
                    'assigned_doctor_id' => $staffId,
                    'bhw_companion_id' => null,
                    'scheduled_date' => $appointment->appointment_date?->toDateString() ?: now()->toDateString(),
                    'scheduled_time' => $this->normalizeAppointmentTime((string) $appointment->appointment_time) ?: now()->format('H:i'),
                    'estimated_duration_minutes' => 30,
                    'session_mode' => 'in_app',
                    'session_link' => null,
                    'session_token' => Str::random(48),
                    'status' => 'active',
                    'started_at' => now(),
                    'ended_at' => null,
                    'actual_duration_minutes' => null,
                    'consultation_id' => $consultation->id,
                    'cancellation_reason' => null,
                    'cancelled_at' => null,
                ]);
            } else {
                $session->update([
                    'assigned_doctor_id' => $session->assigned_doctor_id ?: $staffId,
                    'scheduled_date' => $session->scheduled_date ?: ($appointment->appointment_date?->toDateString() ?: now()->toDateString()),
                    'scheduled_time' => $session->scheduled_time ?: ($this->normalizeAppointmentTime((string) $appointment->appointment_time) ?: now()->format('H:i')),
                    'estimated_duration_minutes' => $session->estimated_duration_minutes ?: 30,
                    'session_mode' => $session->session_mode ?: 'in_app',
                    'session_token' => $session->session_token ?: Str::random(48),
                    'status' => in_array($session->status, ['scheduled', 'waiting', 'paused'], true)
                        ? 'active'
                        : $session->status,
                    'started_at' => $session->started_at ?: now(),
                    'consultation_id' => $session->consultation_id ?: $consultation->id,
                ]);
            }

            $appointment->update([
                'status' => 'ongoing',
                'handled_by' => $staffId,
                'notes' => $appointment->notes ?: 'Telemedicine session opened by staff.',
            ]);

            $webRtc = app(WebRtcService::class);
            $room = $webRtc->createRoomIfMissing($session->fresh());

            return [
                'history' => false,
                'appointment' => $appointment->fresh([
                    'resident',
                    'handler',
                    'rhu',
                    'consultation',
                    'queueTicket',
                    'telemedicineRequest.session',
                ]),
                'consultation' => $consultation->fresh(),
                'telemedicine_request' => $telemedicineRequest->fresh([
                    'residentProfile.user',
                    'residentProfile.barangay',
                    'rhu',
                    'queueTicket',
                    'session.assignedDoctor',
                    'session.bhwCompanion',
                ]),
                'telemedicine_session' => $session->fresh([
                    'assignedDoctor',
                    'bhwCompanion',
                    'request.residentProfile.user',
                    'request.residentProfile.barangay',
                    'request.rhu',
                    'notes',
                    'referrals',
                    'consultation',
                ]),
                'room' => $room,
            ];
        });

        $session = $result['telemedicine_session'] ?? null;

        if (!empty($result['history'])) {
            return response()->json([
                'message' => 'Telemedicine session is already ended. Opening consultation record instead.',
                'mode' => 'telemedicine_history',
                'telemedicine' => true,
                'appointment' => $result['appointment'],
                'consultation' => $result['consultation'],
                'telemedicine_request' => $result['telemedicine_request'],
                'telemedicine_session' => $session,
                'session' => $session,
                'redirect_to' => 'consultation',
            ]);
        }

        if (!$session || !$session->id) {
            return response()->json([
                'message' => 'Telemedicine room was not returned by the backend.',
            ], 500);
        }

        $roomUrl = $this->roomUrlForSession($session);
        $roomName = $this->sessionRoomName($session);

        $sessionPayload = array_merge(
            $session->toArray(),
            [
                'room_name' => $roomName,
                'room_url' => $roomUrl,
                'join_url' => $roomUrl,
                'roomUrl' => $roomUrl,
                'joinUrl' => $roomUrl,
            ]
        );

        return response()->json([
            'message' => 'Telemedicine session opened.',
            'mode' => 'telemedicine',
            'telemedicine' => true,

            'appointment' => $result['appointment'],
            'consultation' => $result['consultation'],
            'telemedicine_request' => $result['telemedicine_request'],
            'telemedicine_session' => $sessionPayload,
            'telemedicineSession' => $sessionPayload,
            'session' => $sessionPayload,

            'room' => [
                'name' => $roomName,
                'url' => $roomUrl,
                'join_url' => $roomUrl,
                'room_url' => $roomUrl,
            ],

            'room_name' => $roomName,
            'room_url' => $roomUrl,
            'join_url' => $roomUrl,
            'roomUrl' => $roomUrl,
            'joinUrl' => $roomUrl,

            'redirect_to' => 'telemedicine_room',
        ], 201);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function createOrReuseConsultation(
        Appointment $appointment,
        int $staffId,
        string $status,
        string $note
    ): Consultation {
        $consultation = null;

        if (Schema::hasColumn('consultations', 'appointment_id')) {
            $consultation = Consultation::query()
                ->where('appointment_id', $appointment->id)
                ->first();
        }

        $chiefComplaint = $appointment->reason
            ?: $this->extractPurposeLine($appointment->purpose, 'Reason')
            ?: $appointment->purpose
            ?: 'Appointment consultation';

        $symptoms = $appointment->symptoms
            ?: $this->extractPurposeLine($appointment->purpose, 'Symptoms');

        if (!$consultation) {
            $data = [
                'appointment_id' => $appointment->id,
                'user_id' => $appointment->user_id,
                'attended_by' => $staffId,
                'consultation_date' => now()->toDateString(),
                'chief_complaint' => $chiefComplaint,
                'diagnosis' => null,
                'treatment' => null,
                'status' => $status,
                'subjective' => $chiefComplaint,
                'objective' => $symptoms,
                'assessment' => null,
                'plan' => null,
                'notes' => $note,
                'started_at' => now(),
            ];

            return Consultation::create(
                $this->filterTablePayload('consultations', $data)
            );
        }

        if ($consultation->status !== 'completed') {
            $data = [
                'attended_by' => $consultation->attended_by ?: $staffId,
                'status' => $status,
                'chief_complaint' => $consultation->chief_complaint ?: $chiefComplaint,
                'subjective' => $consultation->subjective ?: $chiefComplaint,
                'objective' => $consultation->objective ?: $symptoms,
                'notes' => $consultation->notes ?: $note,
                'started_at' => $consultation->started_at ?: now(),
            ];

            $consultation->update(
                $this->filterTablePayload('consultations', $data)
            );
        }

        return $consultation->refresh();
    }

    private function filterTablePayload(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(function ($value, string $column) use ($table) {
                return Schema::hasColumn($table, $column);
            })
            ->all();
    }

    private function currentUserId(Request $request): int
    {
        return (int) (
            $request->user()->user_id
            ?? $request->user()->getKey()
            ?? 0
        );
    }

    private function isOnlineAppointment(Appointment $appointment): bool
    {
        $type = $this->normalizeConsultationType((string) $appointment->consultation_type);
        $purpose = strtolower((string) $appointment->purpose);

        return $type === 'online'
            || str_contains($purpose, 'online')
            || str_contains($purpose, 'telemedicine');
    }

    private function roomUrlForSession(TelemedicineSession $session): string
    {
        return '/telemedicine/room/' . $session->id;
    }

    private function sessionRoomName(TelemedicineSession $session): string
    {
        return (string) (
            $session->room_id
            ?: $session->session_link
            ?: $session->session_token
            ?: 'kaagapay-rhu-session-' . $session->id
        );
    }

    private function parseSymptoms(?string $symptoms): array
    {
        return collect(preg_split('/[,;\n]+/', (string) $symptoms))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

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

        if (Schema::hasColumn('queue_tickets', 'source')) {
            $data['source'] = 'online_appointment';
        }

        return QueueTicket::create(
            $this->filterTablePayload('queue_tickets', $data)
        );
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
                    'cancelled_at' => Schema::hasColumn('queue_tickets', 'cancelled_at') ? now() : null,
                    'cancellation_reason' => Schema::hasColumn('queue_tickets', 'cancellation_reason') ? $reason : null,
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
            'birth_date' => $user->birthday ?? null,
            'sex' => $user->sex ?? null,
            'address' => $user->barangay ?? null,
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