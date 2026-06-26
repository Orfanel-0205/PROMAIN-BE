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

            // Long chief complaint / reason support for mobile booking.
            // Keep the resident-facing main reason clear and capped at 2000.
            'reason' => ['nullable', 'string', 'max:2000'],
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
            'complaint' => ['nullable', 'string', 'max:2000'],
            'symptoms' => ['nullable', 'string', 'max:2000'],

            // Reusable ITR/profile text fields may be forwarded by mobile clients.
            // They are accepted here to avoid accidental 500s, but are only saved
            // by this controller when the target appointment/profile columns exist.
            'medical_history' => ['nullable', 'string', 'max:2000'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'maintenance_medications' => ['nullable', 'string', 'max:2000'],

            'preferred_date' => ['nullable', 'date'],
            'preferred_time' => ['nullable', 'date_format:H:i'],

            'appointment_date' => ['nullable', 'date'],
            'appointment_time' => ['nullable', 'date_format:H:i'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
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

        $reason = trim((string) (
            $validated['reason']
            ?? $validated['chief_complaint']
            ?? $validated['complaint']
            ?? ''
        ));
        $symptoms = trim((string) ($validated['symptoms'] ?? ''));
        $address = trim((string) ($validated['address'] ?? $validated['patient_address'] ?? ''));

        $purpose = trim((string) ($validated['purpose'] ?? ''));

        if ($purpose === '') {
            // Keep purpose short for older schemas where purpose may still be VARCHAR(255).
            // The complete chief complaint is saved in the text column: appointments.reason.
            $shortReason = Str::limit($reason, 180, '...');
            $purpose = collect([
                '[' . strtoupper($consultationType) . ' CONSULTATION]',
                $shortReason ? 'Reason: ' . $shortReason : null,
            ])->filter()->implode("\n");
        } else {
            // Also guard externally supplied purpose against old VARCHAR(255) columns.
            $purpose = Str::limit($purpose, 240, '...');
        }

        if (!$appointmentDate) {
            return response()->json([
                'message' => 'The appointment date field is required.',
                'errors' => [
                    'appointment_date' => ['Please choose your preferred appointment date.'],
                ],
            ], 422);
        }

        if ($reason === '') {
            return response()->json([
                'message' => 'The reason field is required.',
                'errors' => [
                    'reason' => ['Please enter your Chief Complaint / Reason for Consultation.'],
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
            'notes' => $validated['notes'] ?? null,

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
            // Mobile app scope is RHU1 only.
            // Ignore any client-supplied facility value and route all mobile
            // appointment bookings to RHU1 when barangay_id 1 exists.
            $rhuId = 1;

            if (!Barangay::query()->where('barangay_id', $rhuId)->exists()) {
                $rhuId = null;
            }

            $payload['rhu_id'] = $rhuId;
        }

        if (Schema::hasTable('resident_profiles')) {
            $profilePayload = [];

            if ($address !== '' && Schema::hasColumn('resident_profiles', 'address')) {
                $profilePayload['address'] = $address;
            }

            foreach (['medical_history', 'allergies', 'maintenance_medications'] as $profileTextColumn) {
                if (
                    array_key_exists($profileTextColumn, $validated)
                    && Schema::hasColumn('resident_profiles', $profileTextColumn)
                ) {
                    $profilePayload[$profileTextColumn] = $validated[$profileTextColumn] ?: null;
                }
            }

            if ($profilePayload !== []) {
                ResidentProfile::query()->updateOrCreate(
                    ['user_id' => $request->user()->user_id],
                    $profilePayload
                );
            }
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
        // an RHU 1 booking lands in the RHU 1 queue.
        // The frontend never creates queue tickets — this is the single source.
        if (in_array($status, ['confirmed', 'approved', 'scheduled'], true)) {
            $this->createOrSyncQueueTicketForAppointment($appointment->fresh());
            $this->createTelemedicineRequestForOnlineAppointment($appointment->fresh());
        }

        return response()->json([
            'message' => 'Appointment status updated.',
            'appointment' => $appointment->fresh([
                'resident',
                'handler',
                'queueTicket',
                'telemedicineRequest',
            ]),
        ]);
    }

    /**
     * DELETE /api/v1/admin/appointments/{id}
     */
    public function adminDestroy(int $id): JsonResponse
    {
        $appointment = Appointment::query()->findOrFail($id);
        $appointment->delete();

        return response()->json([
            'message' => 'Appointment deleted.',
        ]);
    }

    /**
     * POST /api/v1/admin/appointments/{id}/start-consultation
     */
    public function startConsultation(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::query()
            ->with(['resident', 'queueTicket'])
            ->findOrFail($id);

        if ($appointment->queueTicket && $appointment->queueTicket->status !== 'in_service') {
            return response()->json([
                'message' => 'This appointment can only start consultation when the queue ticket is already in service.',
            ], 422);
        }

        $consultation = Consultation::query()->firstOrCreate(
            ['appointment_id' => $appointment->id],
            [
                'user_id' => $appointment->user_id,
                'attended_by' => $request->user()->user_id,
                'consultation_date' => $appointment->appointment_date ?? now()->toDateString(),
                'chief_complaint' => $appointment->reason ?? $appointment->purpose,
                'diagnosis' => null,
                'treatment' => null,
                'status' => 'open',
                'started_at' => now(),
            ]
        );

        if ($consultation->wasRecentlyCreated === false) {
            $consultation->forceFill([
                'attended_by' => $consultation->attended_by ?: $request->user()->user_id,
                'started_at' => $consultation->started_at ?: now(),
            ])->save();
        }

        $appointment->forceFill([
            'status' => 'ongoing',
            'handled_by' => $request->user()->user_id,
        ])->save();

        return response()->json([
            'message' => 'Consultation started.',
            'consultation' => $consultation->fresh(['resident', 'attendant']),
            'appointment' => $appointment->fresh(['resident', 'handler', 'queueTicket']),
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function createOrSyncQueueTicketForAppointment(Appointment $appointment): void
    {
        if (!Schema::hasTable('queue_tickets')) {
            return;
        }

        if (!Schema::hasColumn('queue_tickets', 'appointment_id')) {
            return;
        }

        if (!$appointment->rhu_id) {
            return;
        }

        if ($appointment->queueTicket) {
            $this->syncExistingQueueTicket($appointment);
            return;
        }

        $residentProfile = ResidentProfile::query()
            ->where('user_id', $appointment->user_id)
            ->first();

        $ticketPayload = [
            'resident_profile_id' => $residentProfile?->id,
            'appointment_id' => $appointment->id,
            'consultation_id' => null,
            'rhu_id' => $appointment->rhu_id,
            'service_type' => $this->serviceTypeFromAppointment($appointment),
            'queue_type' => $this->queueTypeFromAppointment($appointment),
            'source' => 'appointment',
            'priority_score' => $this->priorityScoreForProfile($residentProfile, $appointment),
            'priority_category' => $this->priorityCategoryForProfile($residentProfile, $appointment),
            'is_senior' => (bool) ($residentProfile?->is_senior ?? false),
            'is_pregnant' => (bool) ($residentProfile?->is_pregnant ?? false),
            'is_pwd' => (bool) ($residentProfile?->is_pwd ?? false),
            'is_pediatric' => $this->isPediatric($residentProfile),
            'is_emergency' => $this->isEmergencyAppointment($appointment),
            'is_bhw_endorsed' => false,
            'status' => 'waiting',
            'notes' => $appointment->reason ?? $appointment->purpose,
            'issued_by' => $appointment->handled_by,
        ];

        try {
            $queueTicket = $this->queueService->createTicket($ticketPayload);

            if ($queueTicket && Schema::hasColumn('appointments', 'queue_ticket_id')) {
                $appointment->forceFill([
                    'queue_ticket_id' => $queueTicket->id,
                ])->save();
            }
        } catch (\Throwable) {
            // Do not break appointment approval if queue ticket generation fails.
            // The admin can still approve/schedule the appointment and inspect logs.
        }
    }

    private function syncExistingQueueTicket(Appointment $appointment): void
    {
        $ticket = $appointment->queueTicket;

        if (!$ticket) {
            return;
        }

        $payload = [
            'rhu_id' => $appointment->rhu_id ?: $ticket->rhu_id,
            'service_type' => $this->serviceTypeFromAppointment($appointment),
            'queue_type' => $this->queueTypeFromAppointment($appointment),
            'notes' => $appointment->reason ?? $appointment->purpose ?? $ticket->notes,
        ];

        $ticket->forceFill($this->filterTablePayload('queue_tickets', $payload));
        $ticket->save();
    }

    private function createTelemedicineRequestForOnlineAppointment(Appointment $appointment): void
    {
        if (($appointment->consultation_type ?? 'onsite') !== 'online') {
            return;
        }

        if (!Schema::hasTable('telemedicine_requests')) {
            return;
        }

        if (!Schema::hasColumn('telemedicine_requests', 'appointment_id')) {
            return;
        }

        $existing = TelemedicineRequest::query()
            ->where('appointment_id', $appointment->id)
            ->first();

        if ($existing) {
            return;
        }

        $residentProfile = ResidentProfile::query()
            ->where('user_id', $appointment->user_id)
            ->first();

        $payload = [
            'appointment_id' => $appointment->id,
            'user_id' => $appointment->user_id,
            'resident_profile_id' => $residentProfile?->id,
            'rhu_id' => $appointment->rhu_id,
            'reason' => $appointment->reason ?? $appointment->purpose,
            'status' => 'pending',
            'requested_at' => now(),
        ];

        $payload = $this->filterTablePayload('telemedicine_requests', $payload);

        if ($payload === []) {
            return;
        }

        try {
            TelemedicineRequest::query()->create($payload);
        } catch (\Throwable) {
            // Do not block appointment approval.
        }
    }

    private function buildAdminTelemedicineRoomUrl(TelemedicineSession $session): string
    {
        return '/telemedicine/room/' . $session->id;
    }

    private function serviceTypeFromAppointment(Appointment $appointment): string
    {
        $text = Str::lower((string) (
            $appointment->reason
            ?? $appointment->purpose
            ?? ''
        ));

        if (Str::contains($text, ['prenatal', 'pregnan', 'buntis'])) {
            return 'prenatal_checkup';
        }

        if (Str::contains($text, ['immunization', 'vaccine', 'bakuna'])) {
            return 'immunization';
        }

        if (Str::contains($text, ['family planning'])) {
            return 'family_planning';
        }

        if (Str::contains($text, ['dental', 'tooth', 'ngipin'])) {
            return 'dental';
        }

        if (Str::contains($text, ['laboratory', 'lab'])) {
            return 'laboratory';
        }

        if (Str::contains($text, ['medicine', 'gamot', 'release'])) {
            return 'medicine_release';
        }

        if ($this->isEmergencyAppointment($appointment)) {
            return 'emergency';
        }

        return 'opd_consultation';
    }

    private function queueTypeFromAppointment(Appointment $appointment): string
    {
        return ($appointment->consultation_type ?? 'onsite') === 'online'
            ? 'online_appointment'
            : 'scheduled_appointment';
    }

    private function priorityScoreForProfile(?ResidentProfile $profile, Appointment $appointment): int
    {
        if ($this->isEmergencyAppointment($appointment)) {
            return 100;
        }

        $score = 0;

        if ($profile?->is_pregnant) {
            $score = max($score, 75);
        }

        if ($profile?->is_senior) {
            $score = max($score, 60);
        }

        if ($profile?->is_pwd) {
            $score = max($score, 55);
        }

        if ($this->isPediatric($profile)) {
            $score = max($score, 45);
        }

        return $score;
    }

    private function priorityCategoryForProfile(?ResidentProfile $profile, Appointment $appointment): string
    {
        if ($this->isEmergencyAppointment($appointment)) {
            return 'emergency';
        }

        if ($profile?->is_pregnant) {
            return 'pregnant';
        }

        if ($profile?->is_senior) {
            return 'senior_citizen';
        }

        if ($profile?->is_pwd) {
            return 'pwd';
        }

        if ($this->isPediatric($profile)) {
            return 'pediatric';
        }

        return 'regular';
    }

    private function isPediatric(?ResidentProfile $profile): bool
    {
        $birthdate = $profile?->birth_date
            ?? $profile?->birthdate
            ?? $profile?->date_of_birth
            ?? null;

        if (!$birthdate) {
            return false;
        }

        try {
            return Carbon::parse($birthdate)->age < 5;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isEmergencyAppointment(Appointment $appointment): bool
    {
        $text = Str::lower((string) (
            $appointment->reason
            ?? $appointment->purpose
            ?? ''
        ));

        return Str::contains($text, [
            'emergency',
            'urgent',
            'chest pain',
            'difficulty breathing',
            'hirap huminga',
            'severe bleeding',
            'seizure',
            'fainting',
        ]);
    }

    private function filterTablePayload(string $table, array $payload): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        return collect($payload)
            ->filter(fn ($value, $column) => Schema::hasColumn($table, (string) $column))
            ->all();
    }
}