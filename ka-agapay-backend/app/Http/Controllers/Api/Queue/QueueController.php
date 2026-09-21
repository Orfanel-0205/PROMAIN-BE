<?php
// app/Http/Controllers/Api/Queue/QueueController.php

namespace App\Http\Controllers\Api\Queue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Queue\IssueQueueTicketRequest;
use App\Http\Requests\Queue\QueueListRequest;
use App\Http\Requests\Queue\UpdateQueueStatusRequest;
use App\Http\Resources\Queue\QueueTicketResource;
use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use App\Services\Queue\QueueService;
use App\Support\Rhu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class QueueController extends Controller
{
    public function __construct(private readonly QueueService $queueService)
    {
    }

    private function serviceTypes(): array
    {
        return [
            'opd_consultation',
            'prenatal_checkup',
            'immunization',
            'family_planning',
            'tb_dots',
            'laboratory',
            'dental',
            'emergency',
            'medicine_release',
            'bhw_assisted',
        ];
    }

    private function getUserIdFromRequest(Request $request): ?int
    {
        $user = $request->user();

        if (!$user) {
            return null;
        }

        $userId = $user->user_id ?? $user->id ?? null;

        if (!$userId) {
            return null;
        }

        return (int) $userId;
    }

    /**
     * The FACILITY RHU id (1 or 2) for the current request. Never returns a
     * barangay id — residents are mapped to their facility via barangays.rhu_id.
     */
    private function defaultRhuId(Request $request): int
    {
        return Rhu::normalizeRhuId($request->integer('rhu_id'))
            ?? Rhu::resolveRhuIdFromUser($request->user())
            ?? Rhu::defaultId();
    }

    /**
     * Resolve the RHU a request is actually allowed to touch (always 1 or 2).
     *
     * - super_admin / mho: may operate on ANY RHU (uses the requested rhu_id,
     *   or their own as a default).
     * - everyone else (RHU admin/staff/BHW): is HARD-LOCKED to their assigned
     *   RHU. Any rhu_id they pass is ignored so RHU 1 staff stay on the RHU 1
     *   queue and RHU 2 staff stay on the RHU 2 queue.
     */
    private function scopedRhuId(Request $request, ?int $requested): int
    {
        return Rhu::scopeRhuId($request->user(), $requested);
    }

    private function resolveBarangayIdFromUser(object $user): ?int
    {
        // Only a real barangay id — never a facility rhu_id.
        $direct = $user->barangay_id ?? null;

        if ($direct) {
            return (int) $direct;
        }

        $barangayName = trim((string) ($user->barangay ?? ''));

        if ($barangayName === '' || !Schema::hasTable('barangays')) {
            return null;
        }

        $barangayColumns = Schema::getColumnListing('barangays');

        $query = DB::table('barangays');

        $matched = false;

        if (in_array('name', $barangayColumns, true)) {
            $query->orWhere('name', $barangayName);
            $matched = true;
        }

        if (in_array('barangay_name', $barangayColumns, true)) {
            $query->orWhere('barangay_name', $barangayName);
            $matched = true;
        }

        if (!$matched) {
            return null;
        }

        $barangayId = $query->value('barangay_id');

        return $barangayId ? (int) $barangayId : null;
    }

    private function putIfColumnExists(array &$payload, array $columns, string $column, mixed $value): void
    {
        if (!in_array($column, $columns, true)) {
            return;
        }

        if ($value === null || $value === '') {
            return;
        }

        $payload[$column] = $value;
    }

    private function createResidentProfileForUser(Request $request): ?ResidentProfile
    {
        if (!Schema::hasTable('resident_profiles')) {
            return null;
        }

        $user = $request->user();

        if (!$user) {
            return null;
        }

        $userId = $this->getUserIdFromRequest($request);

        if (!$userId) {
            return null;
        }

        $profileColumns = Schema::getColumnListing('resident_profiles');

        if (!in_array('user_id', $profileColumns, true)) {
            return null;
        }

        $payload = [
            'user_id' => $userId,
        ];

        $this->putIfColumnExists(
            $payload,
            $profileColumns,
            'barangay_id',
            $this->resolveBarangayIdFromUser($user)
        );

        $this->putIfColumnExists($payload, $profileColumns, 'first_name', $user->first_name ?? null);
        $this->putIfColumnExists($payload, $profileColumns, 'middle_name', $user->middle_name ?? null);
        $this->putIfColumnExists($payload, $profileColumns, 'last_name', $user->last_name ?? null);
        $this->putIfColumnExists($payload, $profileColumns, 'suffix', $user->suffix ?? null);

        $birthday =
            $user->birth_date
            ?? $user->birthdate
            ?? $user->birthday
            ?? $user->date_of_birth
            ?? null;

        $this->putIfColumnExists($payload, $profileColumns, 'birth_date', $birthday);
        $this->putIfColumnExists($payload, $profileColumns, 'birthdate', $birthday);
        $this->putIfColumnExists($payload, $profileColumns, 'date_of_birth', $birthday);

        $sex = $user->sex ?? $user->gender ?? null;

        $this->putIfColumnExists($payload, $profileColumns, 'sex', $sex);
        $this->putIfColumnExists($payload, $profileColumns, 'gender', $sex);

        $mobile = $user->mobile_number ?? $user->phone ?? $user->contact_number ?? null;

        $this->putIfColumnExists($payload, $profileColumns, 'mobile_number', $mobile);
        $this->putIfColumnExists($payload, $profileColumns, 'contact_number', $mobile);
        $this->putIfColumnExists($payload, $profileColumns, 'phone_number', $mobile);

        $this->putIfColumnExists($payload, $profileColumns, 'address', $user->address ?? null);

        if (in_array('is_senior', $profileColumns, true)) {
            $payload['is_senior'] = false;
        }

        if (in_array('is_pwd', $profileColumns, true)) {
            $payload['is_pwd'] = false;
        }

        if (in_array('is_pregnant', $profileColumns, true)) {
            $payload['is_pregnant'] = false;
        }

        if (in_array('created_at', $profileColumns, true)) {
            $payload['created_at'] = now();
        }

        if (in_array('updated_at', $profileColumns, true)) {
            $payload['updated_at'] = now();
        }

        try {
            return ResidentProfile::create($payload);
        } catch (\Throwable $e) {
            logger()->warning('[QueueController] Failed to auto-create resident profile.', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveResidentProfile(Request $request): ?ResidentProfile
    {
        $userId = $this->getUserIdFromRequest($request);

        if (!$userId || !Schema::hasTable('resident_profiles')) {
            return null;
        }

        $resident = ResidentProfile::query()
            ->where('user_id', $userId)
            ->first();

        if ($resident) {
            return $resident;
        }

        return $this->createResidentProfileForUser($request);
    }

    public function index(QueueListRequest $request): AnonymousResourceCollection
    {
        $query = QueueTicket::with([
            'residentProfile.barangay',
            'residentProfile.user',
            'rhu',
            'issuedBy',
            'servedBy',
        ])->forRhu($this->scopedRhuId($request, $request->integer('rhu_id')));

        if ($request->filled('service_type')) {
            $query->byServiceType((string) $request->input('service_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('date')) {
            $query->whereDate('issued_at', (string) $request->input('date'));
        } else {
            $query->forToday();
        }

        $tickets = $query
            ->prioritized()
            ->paginate($request->integer('per_page', 20));

        return QueueTicketResource::collection($tickets);
    }

    public function issue(IssueQueueTicketRequest $request): JsonResponse
    {
        $data = $request->validated();

        // SECURITY: force the RHU through the scoped resolver so non-global staff
        // can never issue a ticket to another RHU by changing the payload.
        $data['rhu_id'] = $this->scopedRhuId(
            $request,
            isset($data['rhu_id']) ? (int) $data['rhu_id'] : null
        );

        $ticket = $this->queueService->issueTicket($data);

        return response()->json([
            'message' => 'Queue ticket issued successfully.',
            'data' => new QueueTicketResource($ticket),
        ], 201);
    }

    public function show(QueueTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $ticket->load([
            'residentProfile.barangay',
            'residentProfile.user',
            'rhu',
            'issuedBy',
            'servedBy',
            'logs.performedBy',
        ]);

        return response()->json([
            'data' => new QueueTicketResource($ticket),
        ]);
    }

    public function updateStatus(UpdateQueueStatusRequest $request, QueueTicket $ticket): JsonResponse
    {
        $this->authorize('updateStatus', $ticket);

        if ($ticket->isTerminal()) {
            return response()->json([
                'message' => "Ticket [{$ticket->ticket_number}] is already in terminal state [{$ticket->status}].",
            ], 422);
        }

        $newStatus = (string) $request->input('status');

        $guardResponse = $this->guardOpdConsultationBeforeQueueCompletion($ticket, $newStatus);

        if ($guardResponse) {
            return $guardResponse;
        }

        $updatedTicket = $this->queueService->transitionStatus(
            $ticket,
            $newStatus,
            $request->validated()
        );

        $notification = $this->queueService->lastNotificationResult();

        if (!empty($notification)) {
            $updatedTicket->setAttribute('notification_result', $notification);
        }

        return response()->json([
            'message' => 'Ticket status updated successfully.',
            'data' => new QueueTicketResource($updatedTicket),
            'notification' => $notification,
        ]);
    }

    public function startService(Request $request, QueueTicket $ticket): JsonResponse
    {
        $this->authorize('updateStatus', $ticket);

        $result = $this->queueService->startService($ticket);

        return response()->json([
            'message' => 'Service started. Opening SOAP consultation.',
            'data' => new QueueTicketResource($result['ticket']),
            'consultation' => $result['consultation'],
            'consultation_id' => $result['consultation']->id,
        ]);
    }

    private function guardOpdConsultationBeforeQueueCompletion(
        QueueTicket $ticket,
        string $newStatus
    ): ?JsonResponse {
        if ($newStatus !== 'completed') {
            return null;
        }

        if ((string) $ticket->service_type !== 'opd_consultation') {
            return null;
        }

        if (!Schema::hasTable('consultations')) {
            return response()->json([
                'message' => 'Consultation records table is not available. Queue cannot be completed.',
            ], 422);
        }

        $consultation = null;

        if (Schema::hasColumn('queue_tickets', 'consultation_id') && !empty($ticket->consultation_id)) {
            $consultation = DB::table('consultations')
                ->where('id', $ticket->consultation_id)
                ->first();
        }

        $appointmentId = (int) ($ticket->appointment_id ?? 0);

        if (!$consultation && $appointmentId > 0) {
            $consultation = DB::table('consultations')
                ->where('appointment_id', $appointmentId)
                ->latest('id')
                ->first();
        }

        if (!$consultation && $appointmentId <= 0) {
            return null;
        }

        if (!$consultation) {
            return response()->json([
                'message' => 'Start the SOAP consultation first before completing this OPD queue ticket.',
                'errors' => [
                    'consultation' => [
                        'No consultation record exists for this appointment yet.',
                    ],
                ],
            ], 422);
        }

        if ((string) $consultation->status !== 'completed') {
            return response()->json([
                'message' => 'Complete the SOAP consultation first before completing this OPD queue ticket.',
                'errors' => [
                    'consultation' => [
                        'The consultation exists but is not completed yet.',
                    ],
                ],
            ], 422);
        }

        return null;
    }

    public function callNext(Request $request): JsonResponse
    {
        $this->authorize('callNext', QueueTicket::class);

        $validated = $request->validate([
            'rhu_id' => [
                'nullable',
                'integer',
                Rule::in(Rhu::ids()),
            ],
            'service_type' => [
                'nullable',
                'string',
                Rule::in($this->serviceTypes()),
            ],
        ]);

        $rhuId = $this->scopedRhuId($request, $validated['rhu_id'] ?? null);
        $serviceType = $validated['service_type'] ?? 'opd_consultation';

        $ticket = $this->queueService->callNext($rhuId, $serviceType);

        if (!$ticket) {
            return response()->json([
                'message' => 'No patients currently waiting for this service.',
                'data' => null,
            ]);
        }

        $notification = $this->queueService->lastNotificationResult();
        $ticket->setAttribute('notification_result', $notification);

        return response()->json([
            'message' => "Now calling: {$ticket->ticket_number}",
            'data' => new QueueTicketResource($ticket),
            'notification' => $notification,
        ]);
    }

    public function callPriorityNext(Request $request): JsonResponse
    {
        $this->authorize('callNext', QueueTicket::class);

        $validated = $request->validate([
            'rhu_id' => [
                'nullable',
                'integer',
                Rule::in(Rhu::ids()),
            ],
            'service_type' => [
                'nullable',
                'string',
                Rule::in($this->serviceTypes()),
            ],
        ]);

        $rhuId = $this->scopedRhuId($request, $validated['rhu_id'] ?? null);
        $serviceType = $validated['service_type'] ?? 'opd_consultation';

        $ticket = $this->queueService->callPriorityNext($rhuId, $serviceType);

        if (!$ticket) {
            return response()->json([
                'message' => 'No priority patients (senior, PWD, pregnant, pediatric, or emergency) are currently waiting.',
                'data' => null,
            ]);
        }

        $notification = $this->queueService->lastNotificationResult();
        $ticket->setAttribute('notification_result', $notification);

        return response()->json([
            'message' => "Now calling priority patient: {$ticket->ticket_number}",
            'data' => new QueueTicketResource($ticket),
            'notification' => $notification,
        ]);
    }

    public function live(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rhu_id' => [
                'nullable',
                'integer',
                Rule::in(Rhu::ids()),
            ],
            'service_type' => [
                'nullable',
                'string',
                Rule::in($this->serviceTypes()),
            ],
        ]);

        $rhuId = $this->scopedRhuId($request, $validated['rhu_id'] ?? null);

        $live = $this->queueService->getLiveQueue(
            $rhuId,
            $validated['service_type'] ?? null
        );

        return response()->json([
            'data' => [
                'waiting' => QueueTicketResource::collection($live['waiting']),
                'called' => QueueTicketResource::collection($live['called']),
                'in_service' => QueueTicketResource::collection($live['in_service']),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewSummary', QueueTicket::class);

        $validated = $request->validate([
            'rhu_id' => [
                'nullable',
                'integer',
                Rule::in(Rhu::ids()),
            ],
            'date' => [
                'nullable',
                'date',
                'date_format:Y-m-d',
            ],
        ]);

        $rhuId = $this->scopedRhuId($request, $validated['rhu_id'] ?? null);

        $summary = $this->queueService->getDailySummary(
            $rhuId,
            $validated['date'] ?? null
        );

        return response()->json([
            'data' => $summary,
        ]);
    }

    /**
     * GET /queue/attendance — how many people the RHU actually saw.
     *
     * The daily summary answers "what is happening right now". This answers
     * "how many came", for a day or any range of days, which is the figure a
     * rural health unit is asked for by the municipality and has until now
     * had to count by hand.
     *
     * Two counts, because they are different questions and get confused:
     * VISITS is how many times someone was served, and ATTENDEES is how many
     * different people that was. One patient coming three times in a month is
     * three visits and one attendee. Reporting only visits overstates reach;
     * reporting only attendees hides workload.
     *
     * Attendance means served, not issued a number. Someone who took a
     * ticket and went home was not attended to, and counting them would
     * flatter the figures in the one direction nobody notices.
     */
    public function attendance(Request $request): JsonResponse
    {
        $this->authorize('viewSummary', QueueTicket::class);

        $validated = $request->validate([
            'rhu_id' => ['nullable', 'integer', Rule::in(Rhu::ids())],
            'from' => ['nullable', 'date', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $rhuId = $this->scopedRhuId($request, $validated['rhu_id'] ?? null);

        $from = isset($validated['from']) ? \Carbon\Carbon::parse($validated['from']) : today();
        $to = isset($validated['to']) ? \Carbon\Carbon::parse($validated['to']) : $from->copy();

        if (!Schema::hasTable('queue_tickets')) {
            return response()->json(['data' => $this->emptyAttendance($from, $to)]);
        }

        $base = DB::table('queue_tickets')
            ->whereDate('issued_at', '>=', $from->toDateString())
            ->whereDate('issued_at', '<=', $to->toDateString());

        if ($rhuId !== null && Schema::hasColumn('queue_tickets', 'rhu_id')) {
            $base->where('rhu_id', $rhuId);
        }

        $attendedStatuses = ['completed'];

        $totals = [
            'issued' => (int) (clone $base)->count(),
            'visits' => (int) (clone $base)->whereIn('status', $attendedStatuses)->count(),
            'no_show' => (int) (clone $base)->where('status', 'no_show')->count(),
            'skipped' => (int) (clone $base)->where('status', 'skipped')->count(),
            'cancelled' => (int) (clone $base)->where('status', 'cancelled')->count(),
            'still_open' => (int) (clone $base)->whereIn('status', ['waiting', 'called', 'in_service'])->count(),
        ];

        // Distinct people, where the ticket says who it was for. A walk-in
        // recorded without a patient still counts as a visit and cannot count
        // towards distinct attendees, so the two figures differ honestly.
        $totals['attendees'] = Schema::hasColumn('queue_tickets', 'resident_profile_id')
            ? (int) (clone $base)->whereIn('status', $attendedStatuses)
                ->whereNotNull('resident_profile_id')
                ->distinct()->count('resident_profile_id')
            : $totals['visits'];

        $byDay = (clone $base)
            ->whereIn('status', $attendedStatuses)
            ->selectRaw('DATE(issued_at) as day, COUNT(*) as visits')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->day,
                'visits' => (int) $row->visits,
            ])
            ->all();

        $byService = Schema::hasColumn('queue_tickets', 'service_type')
            ? (clone $base)
                ->whereIn('status', $attendedStatuses)
                ->selectRaw('service_type, COUNT(*) as visits')
                ->groupBy('service_type')
                ->orderByDesc('visits')
                ->get()
                ->map(fn ($row) => [
                    'service_type' => (string) ($row->service_type ?? 'unspecified'),
                    'visits' => (int) $row->visits,
                ])
                ->all()
            : [];

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'rhu_id' => $rhuId,
                'totals' => $totals,
                'appointments' => $this->appointmentAttendance($from, $to, $rhuId),
                'by_day' => $byDay,
                'by_service' => $byService,
            ],
        ]);
    }

    /**
     * The booked half of a day.
     *
     * People reach an RHU two ways: they book, or they walk in and take a
     * number. Counting only the queue answers "how busy were we" and misses
     * every patient who was expected, which is the figure that says whether
     * booking is working at all.
     *
     * BOOKED is how many were expected that day. KEPT is how many of those
     * were seen through. The gap between them is the one worth acting on:
     * a morning of empty slots is staff time already paid for.
     *
     * @return array<string, int>
     */
    private function appointmentAttendance($from, $to, ?int $rhuId): array
    {
        $empty = ['booked' => 0, 'kept' => 0, 'cancelled' => 0, 'did_not_arrive' => 0];

        if (!Schema::hasTable('appointments')) {
            return $empty;
        }

        $base = DB::table('appointments')
            ->whereDate('appointment_date', '>=', $from->toDateString())
            ->whereDate('appointment_date', '<=', $to->toDateString());

        if ($rhuId !== null && Schema::hasColumn('appointments', 'rhu_id')) {
            $base->where('rhu_id', $rhuId);
        }

        $booked = (int) (clone $base)->count();
        $kept = (int) (clone $base)->where('status', 'completed')->count();
        $cancelled = (int) (clone $base)->whereIn('status', ['cancelled', 'rejected'])->count();

        return [
            'booked' => $booked,
            'kept' => $kept,
            'cancelled' => $cancelled,

            // Everything booked that was neither seen nor called off. On a
            // past date these are the people who did not arrive. On today
            // they may still be on their way, which is why this is not
            // called a no-show: the report is read during the day as well
            // as after it.
            'did_not_arrive' => max(0, $booked - $kept - $cancelled),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyAttendance($from, $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rhu_id' => null,
            'totals' => [
                'issued' => 0, 'visits' => 0, 'attendees' => 0,
                'no_show' => 0, 'skipped' => 0, 'cancelled' => 0, 'still_open' => 0,
            ],
            'appointments' => ['booked' => 0, 'kept' => 0, 'cancelled' => 0, 'did_not_arrive' => 0],
            'by_day' => [],
            'by_service' => [],
        ];
    }
    public function myTicket(Request $request): JsonResponse
    {
        $resident = $this->resolveResidentProfile($request);

        if (!$resident) {
            return response()->json([
                'message' => 'Resident profile could not be created for this account.',
                'data' => null,
            ]);
        }

        $ticket = $this->queueService->getActiveTicketForResident((int) $resident->id);

        if (!$ticket) {
            return response()->json([
                'message' => 'You have no active queue ticket today.',
                'data' => null,
            ]);
        }

        return response()->json([
            'data' => new QueueTicketResource($ticket),
        ]);
    }
}
