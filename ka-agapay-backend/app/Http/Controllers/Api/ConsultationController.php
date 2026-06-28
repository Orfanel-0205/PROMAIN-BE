<?php
// app/Http/Controllers/Api/ConsultationController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Support\BoardVisibility;
use App\Support\Rhu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // RHU scoping (authoritative): global-scope roles (super_admin / MHO) may
        // view all RHUs or filter by ?rhu_id; facility-scoped staff are locked to
        // their own RHU. Legacy rows with NULL rhu_id are treated as RHU 1.
        if (Schema::hasColumn('consultations', 'rhu_id')) {
            $effectiveRhu = Rhu::filterRhuId(
                $request->user(),
                $request->integer('rhu_id') ?: null
            );

            if ($effectiveRhu !== null) {
                if ($effectiveRhu === Rhu::DEFAULT_ID) {
                    $query->where(function ($q) {
                        $q->where('rhu_id', Rhu::DEFAULT_ID)
                            ->orWhereNull('rhu_id')
                            ->orWhereNotIn('rhu_id', Rhu::IDS);
                    });
                } else {
                    $query->where('rhu_id', $effectiveRhu);
                }
            }
        }

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

    public function show(Request $request, int $id): JsonResponse
    {
        $relations = $this->consultationRelations();

        $consultation = Consultation::with($relations)->findOrFail($id);

        // Onsite "first attended" capture: the moment RHU staff opens an ACTIVE
        // chart at the desk. Sets the timestamp + ITR snapshot once; never on a
        // completed/cancelled record. The read response is never blocked by this.
        $this->ensureFirstAttended($consultation, $request);

        $consultation = $consultation->fresh($relations);

        $consultation->setAttribute(
            'past_consultations',
            $this->recentConsultationsFor($consultation)
        );

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
            'attended_by' => $this->currentUserId($request),
            'consultation_date' => $validated['consultation_date'] ?? now()->toDateString(),
            'status' => $validated['status'] ?? 'open',
            'started_at' => now(),
        ]);

        if (empty($payload['chief_complaint']) && !empty($payload['subjective'])) {
            $payload['chief_complaint'] = $payload['subjective'];
        }

        $consultation = Consultation::create($payload);

        // Patient is at the desk: stamp first-attended + ITR snapshot on creation.
        $this->ensureFirstAttended($consultation, $request);

        return response()->json([
            'message' => 'Consultation created.',
            'consultation' => $consultation->fresh($this->consultationRelations()),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->updateSoap($request, $id);
    }

    public function updateSoap(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::with('appointment')->findOrFail($id);

        if ($consultation->status === 'completed') {
            return response()->json([
                'message' => 'This consultation is already completed and cannot be edited.',
            ], 422);
        }

        $this->ensureFirstAttended($consultation, $request);

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
            'prescribed_drugs' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(self::VALID_STATUSES)],

            // Vitals / RHU staff-filled (optional; persisted only if the column exists)
            'vital_signs' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'string', 'max:50'],
            'bmi' => ['nullable', 'string', 'max:50'],
            'temperature_celsius' => ['nullable', 'string', 'max:50'],
            'blood_pressure' => ['nullable', 'string', 'max:50'],
            'spo2' => ['nullable', 'string', 'max:50'],
            'heart_rate' => ['nullable', 'string', 'max:50'],
            'visual_acuity' => ['nullable', 'string', 'max:100'],
            'visual_acuity_left' => ['nullable', 'string', 'max:50'],
            'visual_acuity_right' => ['nullable', 'string', 'max:50'],

            // Pediatric client
            'pediatric_client' => ['nullable', 'boolean'],
            'length_cm' => ['nullable', 'string', 'max:50'],
            'head_circumference_cm' => ['nullable', 'string', 'max:50'],
            'skinfold_thickness_cm' => ['nullable', 'string', 'max:50'],
            'waist_cm' => ['nullable', 'string', 'max:50'],
            'hip_cm' => ['nullable', 'string', 'max:50'],
            'limbs_cm' => ['nullable', 'string', 'max:50'],
            'muac_cm' => ['nullable', 'string', 'max:50'],

            // General survey
            'general_survey' => ['nullable', 'string', 'max:100'],
            'awake_and_alert' => ['nullable', 'boolean'],
            'altered_sensorium' => ['nullable', 'boolean'],
        ]);

        $updates = $this->buildSoapUpdates($consultation, $validated, $request);

        DB::transaction(function () use ($consultation, $updates, $request) {
            $consultation->update($updates);

            $freshConsultation = $consultation->fresh('appointment');

            if (($updates['status'] ?? null) === 'cancelled') {
                $this->syncCancelledConsultationFlow($freshConsultation, $request);
            }

            if (($updates['status'] ?? null) === 'completed') {
                $this->syncCompletedConsultationFlow($freshConsultation, $request);
            }
        });

        return response()->json([
            'message' => 'SOAP note saved.',
            'consultation' => $consultation->fresh($this->consultationRelations()),
        ]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::with('appointment')->findOrFail($id);

        $this->ensureFirstAttended($consultation, $request);

        if ($request->all() && $consultation->status !== 'completed') {
            $updates = $this->buildSoapUpdates($consultation, $request->all(), $request);
            unset($updates['status']);
            unset($updates['completed_at']);

            $consultation->update($updates);
            $consultation = Consultation::with('appointment')->findOrFail($id);
        }

        if ($consultation->status !== 'completed') {
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
        }

        DB::transaction(function () use ($consultation, $request) {
            if ($consultation->status !== 'completed') {
                $consultation->update($this->filterConsultationPayload([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'attended_by' => $consultation->attended_by ?: $this->currentUserId($request),
                    'diagnosis' => $consultation->diagnosis ?: $consultation->assessment,
                    'treatment' => $consultation->treatment ?: $consultation->plan,

                    // 3-hour "fresh heatmap signal" window. Visibility/freshness
                    // only — the record itself is kept permanently for reports.
                    'heatmap_posted_at' => now(),
                    'heatmap_signal_expires_at' => now()->addHours(3),
                ]));
            }

            $this->syncCompletedConsultationFlow($consultation->fresh('appointment'), $request);
        });

        return response()->json([
            'message' => 'Consultation completed.',
            'consultation' => $consultation->fresh($this->consultationRelations()),
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
            $completedAt = now();

            $updates['completed_at'] = $completedAt;

            // 3-hour fresh heatmap signal window.
            // This is only for realtime/freshness visibility.
            // The consultation remains permanently available for reports.
            if (Schema::hasColumn('consultations', 'heatmap_posted_at')) {
                $updates['heatmap_posted_at'] = $completedAt;
            }

            if (Schema::hasColumn('consultations', 'heatmap_signal_expires_at')) {
                $updates['heatmap_signal_expires_at'] = $completedAt->copy()->addHours(3);
            }
        } else {
            // Saving without completing = a draft save. Stamp the time so the SOAP
            // page can show "Draft saved …" and drive the draft TTL indicator.
            $updates['draft_saved_at'] = now();
        }

        if (!$consultation->started_at) {
            $updates['started_at'] = now();
        }

        if (!$consultation->attended_by) {
            $updates['attended_by'] = $this->currentUserId($request);
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

    private function syncCompletedConsultationFlow(Consultation $consultation, Request $request): void
    {
        $appointmentId = (int) ($consultation->appointment_id ?? 0);

        $staffId = $this->currentUserId($request);

        if ($appointmentId > 0) {
            $appointmentUpdates = [
                'status' => 'completed',
            ];

            if (Schema::hasColumn('appointments', 'handled_by') && $staffId > 0) {
                $appointmentUpdates['handled_by'] = DB::raw('COALESCE(handled_by, ' . $staffId . ')');
            }

            if (Schema::hasColumn('appointments', 'updated_at')) {
                $appointmentUpdates['updated_at'] = now();
            }

            DB::table('appointments')
                ->where('id', $appointmentId)
                ->update($appointmentUpdates);

            // Stamp completed_at + board_visible_until so the appointment leaves
            // the ACTIVE board after the grace period (kept in Completed/History).
            BoardVisibility::stampAppointmentCompleted($appointmentId);
        }

        // Telemedicine cascade: when a consultation that came from an online
        // appointment is completed, the linked telemedicine session and request
        // must also close so the Appointments/Telemedicine pages never show a
        // completed consultation as still "ongoing" / "Open Room".
        $this->syncCompletedTelemedicine($consultation, $appointmentId);

        if (!Schema::hasTable('queue_tickets')) {
            return;
        }

        $ticketQuery = DB::table('queue_tickets');

        if (Schema::hasColumn('queue_tickets', 'consultation_id')) {
            $ticketQuery->where(function ($q) use ($consultation, $appointmentId) {
                $q->where('consultation_id', $consultation->id);

                if ($appointmentId > 0) {
                    $q->orWhere('appointment_id', $appointmentId);
                }
            });
        } elseif ($appointmentId > 0) {
            $ticketQuery->where('appointment_id', $appointmentId);
        } else {
            return;
        }

        $ticket = $ticketQuery->latest('id')->first();

        if (!$ticket) {
            return;
        }

        $queueUpdates = [
            'status' => 'completed',
        ];

        if (Schema::hasColumn('queue_tickets', 'service_ended_at')) {
            $queueUpdates['service_ended_at'] = now();
        }

        if (Schema::hasColumn('queue_tickets', 'completed_at')) {
            $queueUpdates['completed_at'] = now();
        }

        if (
            Schema::hasColumn('queue_tickets', 'consultation_id')
            && empty($ticket->consultation_id)
        ) {
            $queueUpdates['consultation_id'] = $consultation->id;
        }

        if (Schema::hasColumn('queue_tickets', 'served_by') && $staffId > 0) {
            $queueUpdates['served_by'] = $ticket->served_by ?: $staffId;
        }

        if (
            Schema::hasColumn('queue_tickets', 'service_time_minutes') &&
            !empty($ticket->service_started_at)
        ) {
            $startedAt = strtotime((string) $ticket->service_started_at);

            if ($startedAt) {
                $queueUpdates['service_time_minutes'] = max(
                    0,
                    (int) floor((time() - $startedAt) / 60)
                );
            }
        }

        if (Schema::hasColumn('queue_tickets', 'updated_at')) {
            $queueUpdates['updated_at'] = now();
        }

        DB::table('queue_tickets')
            ->where('id', $ticket->id)
            ->update($queueUpdates);
    }

    /**
     * Close the telemedicine session + request linked to a completed
     * consultation. Safe + non-fatal: only touches rows that exist, and never
     * blocks the consultation completion if telemedicine tables are absent.
     */
    private function syncCompletedTelemedicine(Consultation $consultation, int $appointmentId): void
    {
        if (!Schema::hasTable('telemedicine_sessions')) {
            return;
        }

        try {
            $now = now();

            // Find the session by direct consultation link first, then by the
            // appointment via its telemedicine request.
            $sessionQuery = DB::table('telemedicine_sessions');

            if (Schema::hasColumn('telemedicine_sessions', 'consultation_id')) {
                $sessionQuery->where('consultation_id', $consultation->id);
            } else {
                $sessionQuery->whereRaw('1 = 0');
            }

            $session = $sessionQuery->latest('id')->first();

            $requestId = null;

            if (!$session && $appointmentId > 0 && Schema::hasTable('telemedicine_requests')) {
                $request = DB::table('telemedicine_requests')
                    ->where('appointment_id', $appointmentId)
                    ->latest('id')
                    ->first();

                if ($request) {
                    $requestId = $request->id;

                    $session = DB::table('telemedicine_sessions')
                        ->where('request_id', $request->id)
                        ->latest('id')
                        ->first();
                }
            }

            if ($session) {
                $requestId = $requestId ?? ($session->request_id ?? null);

                if (!in_array((string) $session->status, ['ended', 'no_show', 'cancelled'], true)) {
                    $sessionUpdates = ['status' => 'ended'];

                    if (Schema::hasColumn('telemedicine_sessions', 'ended_at') && empty($session->ended_at)) {
                        $sessionUpdates['ended_at'] = $now;
                    }

                    if (
                        Schema::hasColumn('telemedicine_sessions', 'consultation_id')
                        && empty($session->consultation_id)
                    ) {
                        $sessionUpdates['consultation_id'] = $consultation->id;
                    }

                    if (
                        Schema::hasColumn('telemedicine_sessions', 'actual_duration_minutes')
                        && empty($session->actual_duration_minutes)
                        && !empty($session->started_at)
                    ) {
                        $startedAt = strtotime((string) $session->started_at);

                        if ($startedAt) {
                            $sessionUpdates['actual_duration_minutes'] = max(
                                1,
                                (int) floor(($now->getTimestamp() - $startedAt) / 60)
                            );
                        }
                    }

                    if (Schema::hasColumn('telemedicine_sessions', 'updated_at')) {
                        $sessionUpdates['updated_at'] = $now;
                    }

                    DB::table('telemedicine_sessions')
                        ->where('id', $session->id)
                        ->update($sessionUpdates);
                }
            }

            if ($requestId && Schema::hasTable('telemedicine_requests')) {
                DB::table('telemedicine_requests')
                    ->where('id', $requestId)
                    ->whereNotIn('status', ['completed', 'rejected', 'cancelled'])
                    ->update(array_filter([
                        'status' => 'completed',
                        'updated_at' => Schema::hasColumn('telemedicine_requests', 'updated_at') ? $now : null,
                    ], fn ($value) => $value !== null));

                BoardVisibility::stampTelemedicineRequestCompleted((int) $requestId);
            }
        } catch (\Throwable $e) {
            logger()->warning('[ConsultationController] telemedicine completion sync failed.', [
                'consultation_id' => $consultation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncCancelledConsultationFlow(Consultation $consultation, Request $request): void
    {
        $appointmentId = (int) ($consultation->appointment_id ?? 0);

        if ($appointmentId <= 0) {
            return;
        }

        $staffId = $this->currentUserId($request);

        $appointmentUpdates = [
            'status' => 'cancelled',
        ];

        if (Schema::hasColumn('appointments', 'handled_by') && $staffId > 0) {
            $appointmentUpdates['handled_by'] = DB::raw('COALESCE(handled_by, ' . $staffId . ')');
        }

        if (Schema::hasColumn('appointments', 'updated_at')) {
            $appointmentUpdates['updated_at'] = now();
        }

        DB::table('appointments')
            ->where('id', $appointmentId)
            ->update($appointmentUpdates);

        if (!Schema::hasTable('queue_tickets')) {
            return;
        }

        $queueUpdates = [
            'status' => 'cancelled',
        ];

        if (Schema::hasColumn('queue_tickets', 'cancelled_at')) {
            $queueUpdates['cancelled_at'] = now();
        }

        if (Schema::hasColumn('queue_tickets', 'cancellation_reason')) {
            $queueUpdates['cancellation_reason'] = 'Consultation cancelled by RHU staff.';
        }

        if (Schema::hasColumn('queue_tickets', 'updated_at')) {
            $queueUpdates['updated_at'] = now();
        }

        DB::table('queue_tickets')
            ->where('appointment_id', $appointmentId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->update($queueUpdates);
    }

    private function currentUserId(Request $request): int
    {
        $user = $request->user();

        return (int) (
            $user->user_id
            ?? $user->id
            ?? $user->getKey()
            ?? 0
        );
    }

    private function consultationRelations(): array
    {
        // Includes the patient's ITR/profile + queue ticket so the SOAP page can
        // show the "Patient ITR Snapshot" panel after every load/save.
        $relations = [
            'resident.residentProfile.barangay',
            'attendant',
            'appointment.queueTicket',
            'queueTicket',
        ];

        if (method_exists(Consultation::class, 'firstAttendant')) {
            $relations[] = 'firstAttendant';
        }

        if (method_exists(Consultation::class, 'medicalReports')) {
            $relations[] = 'medicalReports';
        }

        return $relations;
    }

    /**
     * Capture the first time staff opened/worked an ACTIVE chart at the desk,
     * and snapshot the patient's ITR once for record consistency. Idempotent and
     * non-fatal: never blocks reads/writes, never touches completed records.
     */
    private function ensureFirstAttended(Consultation $consultation, Request $request): void
    {
        if (!Schema::hasColumn('consultations', 'first_attended_at')) {
            return;
        }

        $status = strtolower((string) $consultation->status);

        if (in_array($status, ['completed', 'cancelled'], true)) {
            return;
        }

        if (!empty($consultation->first_attended_at)) {
            return;
        }

        $updates = [
            'first_attended_at' => now(),
            'first_attended_by' => $consultation->first_attended_by ?: $this->currentUserId($request),
        ];

        if (Schema::hasColumn('consultations', 'itr_snapshot') && empty($consultation->itr_snapshot)) {
            $updates['itr_snapshot'] = $this->buildItrSnapshot($consultation);
        }

        try {
            $consultation->forceFill($this->filterConsultationPayload($updates))->save();
        } catch (\Throwable $e) {
            logger()->warning('[ConsultationController] first-attended capture failed.', [
                'consultation_id' => $consultation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildItrSnapshot(Consultation $consultation): array
    {
        $consultation->loadMissing('resident.residentProfile.barangay');

        $user = $consultation->resident;
        $profile = $user?->residentProfile;

        return [
            'captured_at' => now()->toIso8601String(),
            'full_name' => $user?->full_name,
            'sex' => $user?->sex ?? $profile?->sex ?? $profile?->gender,
            'birth_date' => optional($user?->birthday)->toDateString()
                ?? optional($profile?->birth_date)->toDateString()
                ?? optional($profile?->birthdate)->toDateString(),
            'mobile_number' => $user?->mobile_number ?? $profile?->mobile_number ?? $profile?->contact_number,
            'barangay' => $profile?->barangay?->name ?? $user?->barangay,
            'address' => $profile?->address,
            'civil_status' => $profile?->civil_status,
            'philhealth' => $profile?->philhealth_number ?? $profile?->philhealth_no,
            'guardian_name' => $profile?->guardian_name,
            'emergency_contact_name' => $profile?->emergency_contact_name,
            'emergency_contact_number' => $profile?->emergency_contact_number,
            'allergies' => $profile?->allergies,
            'past_medical_history' => $profile?->past_medical_history ?? $profile?->medical_history,
            'maintenance_medications' => $profile?->maintenance_medications,
            'family_history' => $profile?->family_history,
            'personal_social_history' => $profile?->personal_social_history,
        ];
    }

    private function recentConsultationsFor(Consultation $consultation): array
    {
        return Consultation::query()
            ->where('user_id', $consultation->user_id)
            ->where('id', '!=', $consultation->id)
            ->orderByDesc('consultation_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'consultation_date', 'diagnosis', 'chief_complaint', 'status', 'completed_at'])
            ->map(fn (Consultation $c) => [
                'id' => $c->id,
                'consultation_date' => optional($c->consultation_date)->toDateString(),
                'diagnosis' => $c->diagnosis,
                'chief_complaint' => $c->chief_complaint,
                'status' => $c->status,
                'completed_at' => optional($c->completed_at)->toIso8601String(),
            ])
            ->all();
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
