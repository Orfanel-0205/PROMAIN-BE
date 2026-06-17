<?php
// app/Http/Controllers/Api/Queue/QueueController.php

namespace App\Http\Controllers\Api\Queue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Queue\IssueQueueTicketRequest;
use App\Http\Requests\Queue\QueueListRequest;
use App\Http\Requests\Queue\UpdateQueueStatusRequest;
use App\Http\Resources\Queue\QueueTicketResource;
use App\Models\QueueTicket;
use App\Services\Queue\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QueueController extends Controller
{
    public function __construct(private readonly QueueService $queueService)
    {
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
            ?? $user?->residentProfile?->barangay_id
            ?? 0
        );

        if ($userRhuId > 0) {
            return $userRhuId;
        }

        $firstBarangayId = DB::table('barangays')
            ->orderBy('barangay_id')
            ->value('barangay_id');

        return (int) ($firstBarangayId ?: 1);
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
        $resident = $request->user()?->residentProfile;

        if (!$resident) {
            return response()->json([
                'message' => 'No resident profile linked to your account.',
            ], 404);
        }

        $ticket = QueueTicket::with(['rhu', 'logs.performedBy'])
            ->where('resident_profile_id', $resident->id)
            ->forToday()
            ->whereIn('status', ['waiting', 'called', 'in_service'])
            ->prioritized()
            ->first();

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