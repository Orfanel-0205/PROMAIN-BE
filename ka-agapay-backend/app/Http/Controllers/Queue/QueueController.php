<?php

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

class QueueController extends Controller
{
    public function __construct(private readonly QueueService $queueService)
    {
    }

    /**
     * GET /api/v1/queue
     * List queue tickets with filters. For staff dashboard.
     */
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

    /**
     * POST /api/v1/queue/issue
     * Issue a new queue ticket for a resident.
     */
    public function issue(IssueQueueTicketRequest $request): JsonResponse
    {
        $ticket = $this->queueService->issueTicket($request->validated());

        return response()->json([
            'message' => 'Queue ticket issued successfully.',
            'data' => new QueueTicketResource($ticket),
        ], 201);
    }

    /**
     * GET /api/v1/queue/{ticket}
     * Show a single queue ticket with full detail.
     */
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

    /**
     * PATCH /api/v1/queue/{ticket}/status
     * Update the status of a queue ticket.
     */
    public function updateStatus(UpdateQueueStatusRequest $request, QueueTicket $ticket): JsonResponse
    {
        $this->authorize('updateStatus', $ticket);

        if ($ticket->isTerminal()) {
            return response()->json([
                'message' => "Ticket [{$ticket->ticket_number}] is already in a terminal state [{$ticket->status}] and cannot be modified.",
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

    /**
     * POST /api/v1/queue/call-next
     * Call the next highest-priority waiting ticket.
     */
    public function callNext(Request $request): JsonResponse
    {
        $this->authorize('callNext', QueueTicket::class);

        $validated = $request->validate([
            'rhu_id' => ['required', 'integer', 'exists:barangays,barangay_id'],
            'service_type' => [
                'required',
                'string',
                'in:opd_consultation,prenatal_checkup,immunization,family_planning,tb_dots,laboratory,dental,emergency,medicine_release,bhw_assisted',
            ],
        ]);

        $ticket = $this->queueService->callNext(
            (int) $validated['rhu_id'],
            (string) $validated['service_type']
        );

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

    /**
     * GET /api/v1/queue/live
     * Live queue display for TV monitors or kiosks.
     */
    public function live(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rhu_id' => ['required', 'integer', 'exists:barangays,barangay_id'],
            'service_type' => ['nullable', 'string'],
        ]);

        $live = $this->queueService->getLiveQueue(
            (int) $validated['rhu_id'],
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

    /**
     * GET /api/v1/queue/summary
     * Daily statistics summary for admin/MHO dashboard.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewSummary', QueueTicket::class);

        $validated = $request->validate([
            'rhu_id' => ['required', 'integer', 'exists:barangays,barangay_id'],
            'date' => ['nullable', 'date', 'date_format:Y-m-d'],
        ]);

        $summary = $this->queueService->getDailySummary(
            (int) $validated['rhu_id'],
            $validated['date'] ?? null
        );

        return response()->json([
            'data' => $summary,
        ]);
    }

    /**
     * GET /api/v1/queue/my-ticket
     * Allow a resident to check their own active ticket.
     */
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