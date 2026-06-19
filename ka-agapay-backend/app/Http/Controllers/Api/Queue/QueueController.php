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

    private function defaultRhuId(Request $request): int
    {
        $inputRhuId = $request->integer('rhu_id');

        if ($inputRhuId > 0) {
            return $inputRhuId;
        }

        $user = $request->user();

        $userRhuId = (int) (
            $user?->rhu_id
            ?? $user?->barangay_id
            ?? 0
        );

        if ($userRhuId > 0) {
            return $userRhuId;
        }

        $userId = $this->getUserIdFromRequest($request);

        if ($userId && Schema::hasTable('resident_profiles')) {
            $residentBarangayId = DB::table('resident_profiles')
                ->where('user_id', $userId)
                ->value('barangay_id');

            if ((int) $residentBarangayId > 0) {
                return (int) $residentBarangayId;
            }
        }

        $firstBarangayId = DB::table('barangays')
            ->orderBy('barangay_id')
            ->value('barangay_id');

        return (int) ($firstBarangayId ?: 1);
    }

    private function resolveBarangayIdFromUser(object $user): ?int
    {
        $direct = $user->barangay_id ?? $user->rhu_id ?? null;

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
            'rhu',
            'issuedBy',
            'servedBy',
        ])->forRhu($request->integer('rhu_id'));

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
        $ticket = $this->queueService->issueTicket($request->validated());

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

        $updatedTicket = $this->queueService->transitionStatus(
            $ticket,
            (string) $request->input('status'),
            $request->validated()
        );

        return response()->json([
            'message' => 'Ticket status updated successfully.',
            'data' => new QueueTicketResource($updatedTicket),
        ]);
    }

    public function callNext(Request $request): JsonResponse
    {
        $this->authorize('callNext', QueueTicket::class);

        $validated = $request->validate([
            'rhu_id' => [
                'nullable',
                'integer',
                Rule::exists('barangays', 'barangay_id'),
            ],
            'service_type' => [
                'nullable',
                'string',
                Rule::in($this->serviceTypes()),
            ],
        ]);

        $rhuId = (int) ($validated['rhu_id'] ?? $this->defaultRhuId($request));
        $serviceType = $validated['service_type'] ?? 'opd_consultation';

        $ticket = $this->queueService->callNext($rhuId, $serviceType);

        if (!$ticket) {
            return response()->json([
                'message' => 'No patients currently waiting for this service.',
                'data' => null,
            ]);
        }

        return response()->json([
            'message' => "Now calling: {$ticket->ticket_number}",
            'data' => new QueueTicketResource($ticket),
        ]);
    }

    public function live(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rhu_id' => [
                'nullable',
                'integer',
                Rule::exists('barangays', 'barangay_id'),
            ],
            'service_type' => [
                'nullable',
                'string',
                Rule::in($this->serviceTypes()),
            ],
        ]);

        $rhuId = (int) ($validated['rhu_id'] ?? $this->defaultRhuId($request));

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
                Rule::exists('barangays', 'barangay_id'),
            ],
            'date' => [
                'nullable',
                'date',
                'date_format:Y-m-d',
            ],
        ]);

        $rhuId = (int) ($validated['rhu_id'] ?? $this->defaultRhuId($request));

        $summary = $this->queueService->getDailySummary(
            $rhuId,
            $validated['date'] ?? null
        );

        return response()->json([
            'data' => $summary,
        ]);
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