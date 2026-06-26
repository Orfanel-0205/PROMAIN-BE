<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Queue\QueueTicketResource;
use App\Models\Appointment;
use App\Models\Barangay;
use App\Models\Consultation;
use App\Models\ResidentProfile;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Services\Queue\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function __construct(private readonly QueueService $queueService)
    {
    }

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
            'address' => ['nullable', 'string', 'max:500'],
            'patient_address' => ['nullable', 'string', 'max:500'],
            'rhu_id' => ['nullable', 'integer'],
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
        $address = trim((string) ($validated['address'] ?? $validated['patient_address'] ?? ''));

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

        $payload = [
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
            'address' => $address ?: null,
            'patient_address' => $address ?: null,
        ];

        if (Schema::hasColumn('appointments', 'rhu_id')) {
            // The patient selects the RHU1 queue when booking. If the app did not
            // send one, fall back to the resident's barangay so the appointment
            // still routes to a queue on approval.
            $rhuId = $validated['rhu_id'] ?? null;

            // Guard the foreign key: only keep an rhu_id that actually exists.
            if ($rhuId && !Barangay::query()->where('barangay_id', $rhuId)->exists()) {
                $rhuId = null;
            }

            if (!$rhuId) {
                $rhuId = ResidentProfile::query()
                    ->where('user_id', $request->user()->user_id)
                    ->value('barangay_id');
            }

            $payload['rhu_id'] = $rhuId ?: null;
        }

        if (
            $address !== ''
            && Schema::hasTable('resident_profiles')
            && Schema::hasColumn('resident_profiles', 'address')
        ) {
            ResidentProfile::query()->updateOrCreate(
                ['user_id' => $request->user()->user_id],
                ['address' => $address]
            );
        }

        $appointment = new Appointment();
        $appointment->forceFill($this->filterTablePayload('appointments', $payload));
        $appointment->save();

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
                'ongoing',
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

        $appointment->forceFill([
            'status' => 'cancelled',
            'notes' => $validated['notes'] ?? 'Cancelled by patient.',
        ])->save();

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
        // queueTicket + consultation are eager loaded so the Appointment Board
        // can gate its actions (e.g. only allow "Start Consultation" once the
        // patient's OPD queue ticket is in_service, and link to the SOAP record).
        $query = Appointment::query()
            ->with(['resident', 'handler', 'queueTicket', 'consultation'])
            ->latest();

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', $request->query('status'));
        }

        if (
            $request->filled('type')
            && $request->query('type') !== 'all'
            && Schema::hasColumn('appointments', 'consultation_type')
        ) {
            $query->where('consultation_type', $request->query('type'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));

            $query->where(function ($q) use ($search) {
                $q->where('purpose', 'like', "%{$search}%");

                if (Schema::hasColumn('appointments', 'reason')) {
                    $q->orWhere('reason', 'like', "%{$search}%");
                }

                if (Schema::hasColumn('appointments', 'symptoms')) {
                    $q->orWhere('symptoms', 'like', "%{$search}%");
                }

                $q->orWhereHas('resident', function ($userQuery) use ($search) {
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
            ->with(['resident', 'handler', 'queueTicket'])
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
            Schema::hasTable('telemedicine_requests') &&
            Schema::hasColumn('telemedicine_requests', 'appointment_id')
        ) {
            $telemedicineRequest = TelemedicineRequest::query()
                ->with(['session.assignedDoctor', 'session.notes'])
                ->where('appointment_id', $appointment->id)
                ->latest()
                ->first();

            $data['telemedicine_request'] = $telemedicineRequest;

            if ($telemedicineRequest?->session) {
                $data['telemedicine_room_url'] = $this->buildAdminTelemedicineRoomUrl(
                    $telemedicineRequest->session
                );
            }
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
                'ongoing',
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

        $appointment->forceFill($this->filterTablePayload('appointments', $updateData));
        $appointment->save();

        // When an appointment is approved/scheduled/confirmed, create or sync its
        // queue ticket. The ticket is routed strictly by appointment.rhu_id, so
        // an RHU 1 booking lands in the RHU 1 queue (and vice versa).
        // The frontend never creates queue tickets — this is the single source.
        $queueTicket = null;

        if (in_array($status, ['approved', 'scheduled', 'confirmed'], true)) {
            try {
                $queueTicket = $this->queueService->syncAppointmentToQueue($appointment->fresh());
            } catch (\Throwable $e) {
                // Queue sync failure must NOT block the approval itself.
                logger()->warning('[AppointmentController] Queue sync failed after approval.', [
                    'appointment_id' => $appointment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => $queueTicket
                ? 'Appointment approved and added to the RHU queue.'
                : 'Appointment status updated.',
            'appointment' => $appointment->fresh(['resident', 'handler', 'rhu']),
            'queue_ticket' => $queueTicket
                ? new QueueTicketResource($queueTicket)
                : null,
        ]);
    }

    /**
     * POST /api/v1/admin/appointments/{id}/start-consultation
     *
     * FIXED:
     * - Online appointment now creates/opens a telemedicine session.
     * - Appointment is marked ONGOING, not COMPLETED.
     * - Consultation is only completed when SOAP is finalized/completed.
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

        if ($this->appointmentIsOnline($appointment)) {
            return $this->startTelemedicineFromAppointment($request, $appointment);
        }

        return $this->startOnsiteConsultationFromAppointment($request, $appointment);
    }

    private function startOnsiteConsultationFromAppointment(
        Request $request,
        Appointment $appointment
    ): JsonResponse {
        return DB::transaction(function () use ($request, $appointment) {
            $consultation = $this->findExistingConsultation($appointment);

            if (!$consultation) {
                $consultation = $this->createConsultationFromAppointment(
                    appointment: $appointment,
                    attendedBy: $request->user()->user_id,
                    type: 'onsite',
                    status: 'ongoing'
                );
            }

            $this->markAppointmentOngoing(
                $appointment,
                $request->user()->user_id,
                'Onsite consultation started by RHU staff.'
            );

            return response()->json([
                'message' => 'Consultation started.',
                'telemedicine' => false,
                'appointment' => $appointment->fresh(['resident', 'handler']),
                'consultation' => $consultation,
            ], $consultation->wasRecentlyCreated ? 201 : 200);
        });
    }

    private function startTelemedicineFromAppointment(
        Request $request,
        Appointment $appointment
    ): JsonResponse {
        return DB::transaction(function () use ($request, $appointment) {
            $residentProfile = ResidentProfile::query()
                ->where('user_id', $appointment->user_id)
                ->first();

            if (!$residentProfile) {
                return response()->json([
                    'message' => 'Resident profile not found. Cannot start telemedicine.',
                ], 422);
            }

            $rhuId = $this->resolveRhuId($appointment, $residentProfile);

            if (!$rhuId) {
                return response()->json([
                    'message' => 'Unable to resolve RHU/barangay for telemedicine request.',
                ], 422);
            }

            $telemedicineRequest = $this->findOrCreateTelemedicineRequest(
                appointment: $appointment,
                residentProfile: $residentProfile,
                rhuId: $rhuId,
                staffUserId: $request->user()->user_id
            );

            $session = $this->findOrCreateTelemedicineSession(
                telemedicineRequest: $telemedicineRequest,
                appointment: $appointment,
                staffUserId: $request->user()->user_id
            );

            $consultation = $this->findExistingConsultation($appointment);

            if (!$consultation) {
                $consultation = $this->createConsultationFromAppointment(
                    appointment: $appointment,
                    attendedBy: $request->user()->user_id,
                    type: 'online',
                    status: 'ongoing'
                );
            } else {
                $this->updateConsultationAsOngoing($consultation, 'online');
            }

            $session->forceFill($this->filterTablePayload('telemedicine_sessions', [
                'status' => 'active',
                'started_at' => $session->started_at ?: now(),
                'consultation_id' => $consultation->id,
            ]));
            $session->save();

            $telemedicineRequest->forceFill($this->filterTablePayload('telemedicine_requests', [
                'status' => 'scheduled',
                'screened_by' => $request->user()->user_id,
                'screened_at' => $telemedicineRequest->screened_at ?: now(),
                'screening_notes' => $telemedicineRequest->screening_notes
                    ?: 'Online appointment opened as telemedicine consultation.',
            ]));
            $telemedicineRequest->save();

            $this->markAppointmentOngoing(
                $appointment,
                $request->user()->user_id,
                'Telemedicine consultation is ongoing.'
            );

            return response()->json([
                'message' => 'Telemedicine consultation started.',
                'telemedicine' => true,
                'appointment' => $appointment->fresh(['resident', 'handler']),
                'consultation' => $consultation,
                'telemedicine_request' => $telemedicineRequest->fresh([
                    'residentProfile.user',
                    'residentProfile.barangay',
                    'rhu',
                    'session.assignedDoctor',
                    'session.notes',
                ]),
                'telemedicine_session' => $session->fresh([
                    'request.residentProfile.user',
                    'request.residentProfile.barangay',
                    'request.rhu',
                    'assignedDoctor',
                    'notes',
                    'consultation',
                ]),
                'room_url' => $this->buildAdminTelemedicineRoomUrl($session),
            ], 201);
        });
    }

    private function findExistingConsultation(Appointment $appointment): ?Consultation
    {
        if (!Schema::hasColumn('consultations', 'appointment_id')) {
            return null;
        }

        return Consultation::query()
            ->where('appointment_id', $appointment->id)
            ->latest()
            ->first();
    }

    private function createConsultationFromAppointment(
        Appointment $appointment,
        int $attendedBy,
        string $type,
        string $status
    ): Consultation {
        $chiefComplaint = $appointment->reason
            ?: $this->extractPurposeLine($appointment->purpose, 'Reason')
            ?: $appointment->purpose
            ?: 'Consultation';

        $symptoms = $appointment->symptoms
            ?: $this->extractPurposeLine($appointment->purpose, 'Symptoms');

        $payload = [
            'appointment_id' => $appointment->id,
            'user_id' => $appointment->user_id,
            'resident_profile_id' => ResidentProfile::query()
                ->where('user_id', $appointment->user_id)
                ->value('id'),
            'attended_by' => $attendedBy,
            'doctor_id' => $attendedBy,
            'consultation_date' => now()->toDateString(),
            'consultation_type' => $type,
            'chief_complaint' => $chiefComplaint,
            'subjective' => $chiefComplaint,
            'objective' => $symptoms,
            'assessment' => null,
            'plan' => null,
            'diagnosis' => null,
            'treatment' => null,
            'treatment_plan' => null,
            'notes' => ucfirst($type) . ' consultation started from appointment #' . $appointment->id,
            'status' => $status,
            'started_at' => now(),
            'completed_at' => null,
        ];

        $consultation = new Consultation();
        $consultation->forceFill($this->filterTablePayload('consultations', $payload));
        $consultation->save();

        return $consultation;
    }

    private function updateConsultationAsOngoing(Consultation $consultation, string $type): void
    {
        $payload = [
            'status' => 'ongoing',
            'consultation_type' => $type,
            'started_at' => $consultation->started_at ?: now(),
        ];

        $consultation->forceFill($this->filterTablePayload('consultations', $payload));
        $consultation->save();
    }

    private function findOrCreateTelemedicineRequest(
        Appointment $appointment,
        ResidentProfile $residentProfile,
        int $rhuId,
        int $staffUserId
    ): TelemedicineRequest {
        $existing = TelemedicineRequest::query()
            ->where('appointment_id', $appointment->id)
            ->latest()
            ->first();

        if ($existing) {
            if (!in_array($existing->status, ['completed', 'cancelled', 'rejected'], true)) {
                $existing->forceFill($this->filterTablePayload('telemedicine_requests', [
                    'status' => 'screened',
                    'screened_by' => $staffUserId,
                    'screened_at' => $existing->screened_at ?: now(),
                    'screening_notes' => $existing->screening_notes
                        ?: 'Online appointment screened for telemedicine.',
                ]));
                $existing->save();
            }

            return $existing;
        }

        $chiefComplaint = $appointment->reason
            ?: $this->extractPurposeLine($appointment->purpose, 'Reason')
            ?: $appointment->purpose
            ?: 'Online consultation';

        $symptoms = $appointment->symptoms
            ?: $this->extractPurposeLine($appointment->purpose, 'Symptoms');

        $payload = [
            'resident_profile_id' => $residentProfile->id,
            'requested_by' => $appointment->user_id,
            'queue_ticket_id' => $appointment->user_id,
            'appointment_id' => $appointment->id,
            'rhu_id' => $rhuId,
            'endorsed_by_bhw' => null,
            'is_bhw_assisted' => false,
            'bhw_notes' => null,
            'chief_complaint' => $chiefComplaint,
            'urgency_level' => $this->resolveUrgencyLevel($chiefComplaint . ' ' . $symptoms),
            'symptoms' => $symptoms ? [$symptoms] : null,
            'additional_notes' => $appointment->purpose,
            'screened_by' => $staffUserId,
            'screening_notes' => 'Online appointment screened for immediate telemedicine.',
            'screened_at' => now(),
            'status' => 'screened',
            'rejection_reason' => null,
            'cancellation_reason' => null,
            'cancelled_at' => null,
        ];

        $telemedicineRequest = new TelemedicineRequest();
        $telemedicineRequest->forceFill($this->filterTablePayload('telemedicine_requests', $payload));
        $telemedicineRequest->save();

        return $telemedicineRequest;
    }

    private function findOrCreateTelemedicineSession(
        TelemedicineRequest $telemedicineRequest,
        Appointment $appointment,
        int $staffUserId
    ): TelemedicineSession {
        $existing = TelemedicineSession::query()
            ->where('request_id', $telemedicineRequest->id)
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $payload = [
            'request_id' => $telemedicineRequest->id,
            'assigned_doctor_id' => $staffUserId,
            'bhw_companion_id' => null,
            'scheduled_date' => $appointment->appointment_date
                ? $appointment->appointment_date->toDateString()
                : now()->toDateString(),
            'scheduled_time' => $appointment->appointment_time ?: now()->format('H:i'),
            'estimated_duration_minutes' => 15,
            'session_mode' => 'in_app',
            'session_link' => null,
            'session_token' => 'kaagapay-tele-' . Str::lower(Str::random(24)),
            'status' => 'scheduled',
            'started_at' => null,
            'ended_at' => null,
            'actual_duration_minutes' => null,
            'consultation_id' => null,
            'cancellation_reason' => null,
            'cancelled_at' => null,
        ];

        if (Schema::hasColumn('telemedicine_sessions', 'room_id')) {
            $payload['room_id'] = 'kaagapay-' . $telemedicineRequest->id . '-' . Str::lower(Str::random(10));
        }

        if (Schema::hasColumn('telemedicine_sessions', 'room_token')) {
            $payload['room_token'] = Str::random(64);
        }

        if (Schema::hasColumn('telemedicine_sessions', 'ice_servers')) {
            $payload['ice_servers'] = null;
        }

        $session = new TelemedicineSession();
        $session->forceFill($this->filterTablePayload('telemedicine_sessions', $payload));
        $session->save();

        $telemedicineRequest->forceFill($this->filterTablePayload('telemedicine_requests', [
            'status' => 'scheduled',
        ]));
        $telemedicineRequest->save();

        return $session;
    }

    private function markAppointmentOngoing(
        Appointment $appointment,
        int $staffUserId,
        string $notes
    ): void {
        $appointment->forceFill($this->filterTablePayload('appointments', [
            'status' => 'ongoing',
            'handled_by' => $staffUserId,
            'notes' => $appointment->notes ?: $notes,
            'scheduled_at' => Schema::hasColumn('appointments', 'scheduled_at')
                ? ($appointment->scheduled_at ?: now())
                : null,
        ]));

        $appointment->save();
    }

    private function appointmentIsOnline(Appointment $appointment): bool
    {
        $type = strtolower((string) ($appointment->consultation_type ?? ''));

        if ($type === 'online') {
            return true;
        }

        $purpose = strtolower((string) $appointment->purpose);

        return str_contains($purpose, '[online consultation]')
            || str_contains($purpose, 'online consultation')
            || str_contains($purpose, 'telemedicine')
            || str_contains($purpose, 'teleconsultation');
    }

    private function resolveRhuId(Appointment $appointment, ResidentProfile $residentProfile): ?int
    {
        if (
            Schema::hasColumn('appointments', 'rhu_id')
            && !empty($appointment->rhu_id)
        ) {
            return (int) $appointment->rhu_id;
        }

        if (!empty($residentProfile->barangay_id)) {
            return (int) $residentProfile->barangay_id;
        }

        return Barangay::query()
            ->orderBy('barangay_id')
            ->value('barangay_id');
    }

    private function resolveUrgencyLevel(?string $text): string
    {
        $text = strtolower((string) $text);

        if (
            str_contains($text, 'hirap huminga')
            || str_contains($text, 'difficulty breathing')
            || str_contains($text, 'chest pain')
            || str_contains($text, 'severe')
            || str_contains($text, 'emergency')
        ) {
            return 'emergency';
        }

        if (
            str_contains($text, 'lagnat')
            || str_contains($text, 'fever')
            || str_contains($text, 'dugo')
            || str_contains($text, 'blood')
            || str_contains($text, 'urgent')
        ) {
            return 'urgent';
        }

        return 'routine';
    }

    private function buildAdminTelemedicineRoomUrl(TelemedicineSession $session): string
    {
        return "/telemedicine/room/{$session->id}";
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

    private function filterTablePayload(string $table, array $payload): array
    {
        $filtered = [];

        foreach ($payload as $key => $value) {
            if (Schema::hasColumn($table, $key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
