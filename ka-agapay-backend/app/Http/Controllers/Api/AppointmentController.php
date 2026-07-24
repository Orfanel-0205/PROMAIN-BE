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
use App\Support\BoardVisibility;
use App\Support\Rhu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

            // RHU is a FACILITY id (1 or 2), not a barangay id. It is optional —
            // the resident's RHU is derived from their barangay when omitted.
            'rhu_id' => ['nullable', 'integer', Rule::in(Rhu::IDS)],
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

        // Resolve the resident's REAL barangay (kept on the profile). The
        // appointment stores the facility rhu_id (1 or 2) — never a barangay id.
        $barangayId = $this->resolveResidentBarangayId($user);

        // The resident CHOOSES the RHU/health center they want to book (RHU 2 may
        // be nearer than the barangay default). The requested rhu_id is honored
        // FIRST (already validated to be 1 or 2 above); the barangay-derived RHU
        // is only the fallback recommendation when none is sent.
        $rhuId = Rhu::normalizeRhuId($validated['rhu_id'] ?? null)
            ?? Rhu::deriveRhuIdFromBarangayId($barangayId)
            ?? Rhu::resolveRhuIdFromUser($user)
            ?? Rhu::DEFAULT_ID;

        // Block a duplicate only for the SAME date + SAME consultation type while it
        // is still active. This lets a resident keep one online and one onsite
        // appointment on the same day, and re-book after a cancelled/rejected/
        // completed visit.
        $duplicate = Appointment::query()
            ->where('user_id', $user->user_id)
            ->whereDate('appointment_date', $appointmentDate)
            ->where('consultation_type', $consultationType)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->first();

        if ($duplicate) {
            $existing = $duplicate->fresh([
                'resident',
                'handler',
                'rhu',
                'consultation',
                'queueTicket',
                'telemedicineRequest.session',
            ]);

            return response()->json([
                'message' => 'You already have an active ' . $consultationType . ' appointment for this date.',
                'existing_appointment' => $existing,
                'appointment' => $existing,
            ], 409);
        }

        // Slot capacity is PER RHU — RHU 1 and RHU 2 have independent limits.
        if ($appointmentTime && !$this->slotHasCapacity($appointmentDate, $appointmentTime, null, (int) $rhuId)) {
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
            $rhuId,
            $barangayId
        ) {
            // Store the REAL barangay on the profile (NOT the facility rhu).
            $residentProfile = $this->ensureResidentProfile($user, (int) ($barangayId ?: 0));

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
                'latestFollowUp',
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

        // RHU scoping: global staff (super_admin/mho) may filter by rhu_id or see
        // all; every other staff role is HARD-LOCKED to their assigned RHU.
        $requestedRhu = $request->query('rhu_id');
        $requestedRhu = ($requestedRhu === null || $requestedRhu === '' || $requestedRhu === 'all')
            ? null
            : (int) $requestedRhu;

        $effectiveRhu = Rhu::filterRhuId($request->user(), $requestedRhu);

        if ($effectiveRhu !== null) {
            if ($effectiveRhu === Rhu::DEFAULT_ID) {
                // RHU 1 also owns legacy/unmapped rows from the pre-RHU2 era.
                $query->where(function ($q) {
                    $q->where('rhu_id', Rhu::DEFAULT_ID)
                        ->orWhereNull('rhu_id')
                        ->orWhereNotIn('rhu_id', Rhu::IDS);
                });
            } else {
                $query->where('rhu_id', $effectiveRhu);
            }
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

        $this->applyAppointmentBoardFilter($query, $request);

        $appointments = $query->paginate(
            $request->integer('per_page', 50)
        );

        return response()->json($appointments);
    }

    /**
     * Board routing for the admin appointment list. Nothing is ever deleted —
     * this only controls which board a record shows on.
     *
     * board=active (default): ONLY records that still need action
     *   (pending/confirmed/approved/scheduled/ongoing). Closed records
     *   (completed/cancelled/rejected) leave the active board IMMEDIATELY — even
     *   when they have a follow-up. The follow-up stays visible in the Health
     *   Follow-up workflow, so the active board is never used to store closed
     *   visits.
     * board=completed: completed records within the report-retention window.
     * board=cancelled: cancelled + rejected records.
     * board=history: all closed records (completed/cancelled/rejected), incl. archived.
     * board=all: no board filtering (raw list).
     */
    private function applyAppointmentBoardFilter(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        $board = strtolower(trim((string) $request->query('board', 'active')));
        $includeArchived = filter_var($request->query('include_archived', false), FILTER_VALIDATE_BOOLEAN);

        $hasArchived = Schema::hasColumn('appointments', 'archived_at');
        $hasCompletedAt = Schema::hasColumn('appointments', 'completed_at');

        if ($hasCompletedAt && $request->filled('completed_from')) {
            $query->whereDate('completed_at', '>=', $request->query('completed_from'));
        }

        if ($hasCompletedAt && $request->filled('completed_to')) {
            $query->whereDate('completed_at', '<=', $request->query('completed_to'));
        }

        if ($board === 'all') {
            return;
        }

        if ($board === 'completed') {
            $query->where('status', 'completed');

            if ($hasArchived && !$includeArchived) {
                $query->whereNull('archived_at');
            }

            if ($hasCompletedAt && !$request->filled('completed_from')) {
                $retentionStart = now()->subDays(BoardVisibility::retentionDays());

                $query->where(function ($q) use ($retentionStart) {
                    $q->whereNull('completed_at')
                        ->orWhere('completed_at', '>=', $retentionStart);
                });
            }

            // Completed board reads newest-first (most recent visit at the top),
            // overriding the actionable-status ordering used by the Active board.
            $query->reorder();
            if ($hasCompletedAt) {
                $query->orderByRaw('completed_at DESC NULLS LAST');
            }
            $query->orderByDesc('appointment_date')->orderByDesc('appointment_time');

            return;
        }

        if ($board === 'cancelled' || $board === 'rejected' || $board === 'closed') {
            $query->whereIn('status', ['cancelled', 'rejected']);

            return;
        }

        if ($board === 'history') {
            $query->whereIn('status', ['completed', 'cancelled', 'rejected']);

            return;
        }

        // Default: ACTIVE board — genuinely actionable-and-due-TODAY only.
        //  1) actionable status (pending/approved/confirmed/scheduled/ongoing)
        //  2) scheduled for the current date (no stale past/future rows)
        //  3) NOT already clinically resolved — a completed consultation means the
        //     visit is done even if the appointment row still reads 'ongoing'
        //     (that is exactly what surfaced 'Completed' / 'Record saved to
        //     History' rows on the Active board).
        $query->whereIn('status', self::ACTIVE_STATUSES);

        if (! $request->filled('date')) {
            // Onsite appointments are single-day slots, so the Active board locks
            // them to today. Online / telemedicine requests are NOT bound to one
            // calendar day (a doctor may reschedule them to any future date and
            // they run across a 24-hour window), so they stay visible in Active
            // regardless of their scheduled date. An explicit ?date= still wins.
            $query->where(function ($scope) {
                $scope->where(function ($onsite) {
                    // Onsite = anything that is not online/telemedicine (null
                    // consultation_type defaults to onsite) → today only.
                    $onsite->where(function ($t) {
                        $t->whereNull('consultation_type')
                            ->orWhereNotIn('consultation_type', ['online', 'telemedicine']);
                    })->whereDate('appointment_date', now()->toDateString());
                })->orWhereIn('consultation_type', ['online', 'telemedicine']);
            });
        }

        // Still exclude genuinely-resolved rows (completed consultation) for BOTH
        // types, so lifting the telemedicine date restriction does NOT reopen the
        // 'Completed'/'Ended' rows leaking into Active bug.
        $query->whereDoesntHave('consultation', function ($q) {
            $q->where('status', 'completed');
        });

        if ($hasArchived && !$includeArchived) {
            $query->whereNull('archived_at');
        }
    }

    /**
     * Block non-global staff from touching another RHU's appointment.
     * Legacy rows (null / non-1-2 rhu_id) are treated as RHU 1.
     */
    private function guardAppointmentRhu(Request $request, Appointment $appointment): ?JsonResponse
    {
        $user = $request->user();

        if (Rhu::isGlobalScope($user)) {
            return null;
        }

        $userRhu = Rhu::resolveRhuIdFromUser($user) ?? Rhu::DEFAULT_ID;
        $apptRhu = Rhu::normalizeRhuId((int) ($appointment->rhu_id ?? 0)) ?? Rhu::DEFAULT_ID;

        if ($apptRhu !== $userRhu) {
            return response()->json([
                'message' => 'This appointment belongs to a different RHU. You can only manage your assigned RHU.',
            ], 403);
        }

        return null;
    }

    /**
     * GET /api/v1/admin/appointments/{id}
     */
    public function adminShow(Request $request, int $id): JsonResponse
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

        if ($denied = $this->guardAppointmentRhu($request, $appointment)) {
            return $denied;
        }

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

        if ($denied = $this->guardAppointmentRhu($request, $appointment)) {
            return $denied;
        }

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

            if ($targetTime && !$this->slotHasCapacity($targetDate, $targetTime, $appointment->id, Rhu::normalizeRhuId((int) ($appointment->rhu_id ?? 0)))) {
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
            // scheduled_at = the resident's chosen appointment slot, not the approval timestamp.
            if (!$appointment->scheduled_at) {
                $date = $targetDate ?? $appointment->appointment_date;
                $time = $targetTime ?? $appointment->appointment_time ?? '08:00:00';
                $updateData['scheduled_at'] = \Carbon\Carbon::parse("{$date} {$time}");
            }
        }

        if ($status === 'scheduled') {
            $updateData['approved_at'] = $appointment->approved_at ?: now();
            // Use appointment_date + appointment_time, not now().
            $date = $targetDate ?? $appointment->appointment_date;
            $time = $targetTime ?? $appointment->appointment_time ?? '08:00:00';
            $updateData['scheduled_at'] = \Carbon\Carbon::parse("{$date} {$time}");
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

        // Approving / scheduling / confirming an onsite appointment pushes the
        // patient into the RHU queue immediately, so they appear in Queue
        // Management without a separate manual "Add to Queue" step. Best-effort:
        // a queue sync failure must never block the approval itself.
        if (in_array($status, ['confirmed', 'approved', 'scheduled'], true)) {
            $this->ensureQueueTicketForAppointment($appointment);
        }

        // Notify the resident (push + stored notification) of the status change.
        // The NotificationService maps approved/rejected/scheduled/cancelled to a
        // friendly title/message and never blocks the response on failure.
        try {
            app(\App\Services\Notification\NotificationService::class)
                ->notifyAppointmentStatus($appointment->fresh(['resident']));
        } catch (\Throwable $e) {
            report($e);
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

        if ($denied = $this->guardAppointmentRhu($request, $appointment)) {
            return $denied;
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

        if ($denied = $this->guardAppointmentRhu($request, $appointment)) {
            return $denied;
        }

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
                    'status' => 'completed',
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
                    'rhu_id' => Rhu::normalizeRhuId((int) ($appointment->rhu_id ?? 0))
                        ?? Rhu::deriveRhuIdFromBarangayId((int) ($residentProfile->barangay_id ?? 0))
                        ?? Rhu::DEFAULT_ID,

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
                        'rhu_id' => $telemedicineRequest->rhu_id
                            ?: (Rhu::normalizeRhuId((int) ($appointment->rhu_id ?? 0))
                                ?? Rhu::deriveRhuIdFromBarangayId((int) ($residentProfile->barangay_id ?? 0))
                                ?? Rhu::DEFAULT_ID),
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
                // Inherit the RHU facility from the appointment so consultation
                // records are correctly scoped to RHU 1 / RHU 2.
                'rhu_id' => $appointment->rhu_id,
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

    private function slotHasCapacity(?string $date, ?string $time, ?int $ignoreAppointmentId, ?int $rhuId = null): bool
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

        // Capacity is counted PER RHU so RHU 1 and RHU 2 never block each other.
        if ($rhuId !== null && $rhuId > 0) {
            $query->where('rhu_id', $rhuId);
        }

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

    /**
     * Ensure an approved/scheduled/confirmed appointment has a live queue ticket.
     *
     * Delegates to QueueService::syncAppointmentToQueue(), which already:
     * - prevents duplicate tickets for the same appointment_id (re-approving is safe),
     * - resolves the resident profile, facility rhu_id, and OPD service_type,
     * - issues the ticket with status = waiting, issued_at = now() (so it lands on
     *   TODAY's Queue Management board), and the existing queue numbering rules.
     *
     * Onsite consultations only — online/telemedicine appointments are handled by
     * the telemedicine flow, not the onsite queue (mirrors addToQueueFromAppointment).
     *
     * Best-effort: any failure is logged and swallowed so appointment approval
     * (already committed above) never fails because of a queue sync problem.
     */
    private function ensureQueueTicketForAppointment(Appointment $appointment): ?QueueTicket
    {
        try {
            if ($this->normalizeConsultationType($appointment->consultation_type ?? null) === 'online') {
                return null;
            }

            $ticket = DB::transaction(function () use ($appointment) {
                return app(QueueService::class)->syncAppointmentToQueue($appointment);
            });

            Log::info('[AppointmentApproval] Queue ticket ensured', [
                'appointment_id' => $appointment->id,
                'user_id' => $appointment->user_id,
                'rhu_id' => $appointment->rhu_id,
                'status' => $appointment->status,
                'queue_ticket_id' => $ticket?->id,
                'ticket_number' => $ticket?->ticket_number,
                'ticket_status' => $ticket?->status,
            ]);

            return $ticket;
        } catch (Throwable $e) {
            report($e);

            Log::warning('[AppointmentApproval] Queue ticket sync failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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

    /**
     * The resident's REAL barangay id (from their profile, or by name lookup).
     * Used to derive the facility RHU and to persist on the profile.
     */
    private function resolveResidentBarangayId(User $user): ?int
    {
        $profile = ResidentProfile::where('user_id', $user->user_id)->first();

        if ($profile && (int) $profile->barangay_id > 0) {
            return (int) $profile->barangay_id;
        }

        $barangayName = trim((string) ($user->barangay ?? ''));

        if ($barangayName !== '') {
            $barangayId = (int) Barangay::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($barangayName)])
                ->value('barangay_id');

            if ($barangayId > 0) {
                return $barangayId;
            }
        }

        return null;
    }

    /**
     * The resident's FACILITY RHU id (1 or 2), derived from their barangay.
     */
    private function resolveRhuIdFromUser(User $user): ?int
    {
        return Rhu::resolveRhuIdFromUser($user);
    }

    /**
     * Ensure a resident profile exists. $barangayId is the REAL barangay id and
     * is only written when it is a valid (> 0) barangay — never a facility rhu.
     */
    private function ensureResidentProfile(User $user, int $barangayId): ResidentProfile
    {
        $profile = ResidentProfile::where('user_id', $user->user_id)->first();

        if ($profile) {
            if (!$profile->barangay_id && $barangayId > 0) {
                $profile->update(['barangay_id' => $barangayId]);
            }

            return $profile->refresh();
        }

        $payload = [
            'user_id' => $user->user_id,
            'birth_date' => $user->birthday ?? null,
            'sex' => $user->sex ?? null,
            'address' => $user->barangay ?? null,
            'philhealth_no' => null,
        ];

        if ($barangayId > 0) {
            $payload['barangay_id'] = $barangayId;
        }

        return ResidentProfile::create($payload);
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