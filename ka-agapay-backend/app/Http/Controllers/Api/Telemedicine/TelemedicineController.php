<?php
// app/Http/Controllers/Api/Telemedicine/TelemedicineController.php

namespace App\Http\Controllers\Api\Telemedicine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Telemedicine\CreateTelemedicineRequestRequest;
use App\Http\Requests\Telemedicine\ScreenTelemedicineRequestRequest;
use App\Http\Resources\Telemedicine\TelemedicineRequestResource;
use App\Models\Barangay;
use App\Models\TelemedicineRequest;
use App\Services\Telemedicine\TelemedicineService;
use App\Support\BoardVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Schema;

class TelemedicineController extends Controller
{
    public function __construct(
        private readonly TelemedicineService $service
    ) {}

    /**
     * GET /api/v1/telemedicine/requests
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'rhu_id'        => ['nullable', 'integer', 'exists:barangays,barangay_id'],
            'status'        => ['nullable', 'string'],
            'urgency_level' => ['nullable', 'string'],
            'date'          => ['nullable', 'date'],
            'per_page'      => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $rhuId = $validated['rhu_id']
            ?? Barangay::query()->orderBy('barangay_id')->value('barangay_id');

        $query = TelemedicineRequest::with([
                'residentProfile.user',
                'residentProfile.barangay',
                'requestedBy',
                'endorsedByBhw',
                'rhu',
                'screenedBy',
                'queueTicket',
                'session.assignedDoctor',
                'session.bhwCompanion',
            ])
            ->when($rhuId, fn ($q) => $q->forRhu((int) $rhuId))
            ->when(
                $request->filled('status') && $request->status !== 'all',
                fn ($q) => $q->where('status', $request->status)
            )
            ->when(
                $request->filled('urgency_level'),
                fn ($q) => $q->where('urgency_level', $request->urgency_level)
            )
            ->when(
                $request->filled('date'),
                fn ($q) => $q->whereDate('created_at', $request->date)
            );

        $this->applyTelemedicineBoardFilter($query, $request);

        $requests = $query->latest()->paginate($request->integer('per_page', 50));

        return TelemedicineRequestResource::collection($requests);
    }

    /**
     * Completed-record board visibility for the telemedicine board.
     *
     * board=active (default): pending/screened/scheduled + live sessions, hides
     *   archived + completed records past their board window (unless a follow-up
     *   keeps them).
     * board=needs_soap: ended sessions whose SOAP is not yet completed.
     * board=completed: recent completed records (within retention window).
     * board=history: completed/closed records, including archived.
     * board=all: no visibility filtering.
     */
    private function applyTelemedicineBoardFilter(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        $board = strtolower(trim((string) $request->query('board', 'active')));
        $includeArchived = filter_var($request->query('include_archived', false), FILTER_VALIDATE_BOOLEAN);

        $hasArchived = Schema::hasColumn('telemedicine_requests', 'archived_at');
        $hasBoardUntil = Schema::hasColumn('telemedicine_requests', 'board_visible_until');
        $hasCompletedAt = Schema::hasColumn('telemedicine_requests', 'completed_at');
        $hasFollowUps = Schema::hasTable('follow_up_reminders')
            && Schema::hasColumn('telemedicine_requests', 'appointment_id');

        if ($board === 'all') {
            return;
        }

        if ($board === 'needs_soap') {
            $query->whereNotIn('status', ['completed', 'rejected', 'cancelled'])
                ->whereHas('session', function ($s) {
                    $s->where('status', 'ended');
                });

            return;
        }

        if ($board === 'completed') {
            $query->where('status', 'completed');

            if ($hasArchived && !$includeArchived) {
                $query->whereNull('archived_at');
            }

            if ($hasCompletedAt) {
                $retentionStart = now()->subDays(BoardVisibility::retentionDays());

                $query->where(function ($q) use ($retentionStart) {
                    $q->whereNull('completed_at')
                        ->orWhere('completed_at', '>=', $retentionStart);
                });
            }

            return;
        }

        if ($board === 'history') {
            $query->whereIn('status', ['completed', 'rejected', 'cancelled']);

            return;
        }

        // Default: ACTIVE board.
        if ($hasArchived && !$includeArchived) {
            $query->whereNull('archived_at');
        }

        $query->where(function ($outer) use ($hasBoardUntil, $hasFollowUps) {
            $outer->where('status', '!=', 'completed');

            if ($hasBoardUntil) {
                $outer->orWhereNull('board_visible_until')
                    ->orWhere('board_visible_until', '>=', now());
            } else {
                $outer->orWhere('status', 'completed');
            }

            if ($hasFollowUps) {
                $outer->orWhereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('follow_up_reminders')
                        ->whereColumn('follow_up_reminders.appointment_id', 'telemedicine_requests.appointment_id')
                        ->whereIn('follow_up_reminders.status', ['pending', 'scheduled']);
                });
            }
        });
    }

    /**
     * POST /api/v1/telemedicine/requests
     */
    public function store(CreateTelemedicineRequestRequest $request): JsonResponse
    {
        $telemedicineRequest = $this->service->createRequest(
            $request->validated()
        );

        $telemedicineRequest->load([
            'residentProfile.user',
            'residentProfile.barangay',
            'requestedBy',
            'rhu',
            'queueTicket',
            'session.assignedDoctor',
        ]);

        return response()->json([
            'message' => 'Telemedicine request submitted successfully.',
            'data'    => new TelemedicineRequestResource($telemedicineRequest),
        ], 201);
    }

    /**
     * GET /api/v1/telemedicine/requests/{request}
     */
    public function show(TelemedicineRequest $request): JsonResponse
    {
        $request->load([
            'residentProfile.user',
            'residentProfile.barangay',
            'requestedBy',
            'endorsedByBhw',
            'screenedBy',
            'rhu',
            'queueTicket',
            'session.assignedDoctor',
            'session.bhwCompanion',
            'session.notes',
            'session.referrals',
        ]);

        return response()->json([
            'data' => new TelemedicineRequestResource($request),
        ]);
    }

    /**
     * PATCH /api/v1/telemedicine/requests/{request}/screen
     */
    public function screen(
        ScreenTelemedicineRequestRequest $httpRequest,
        TelemedicineRequest $request
    ): JsonResponse {
        $result = $this->service->screenRequest(
            $request,
            $httpRequest->validated()
        );

        $result->load([
            'residentProfile.user',
            'residentProfile.barangay',
            'requestedBy',
            'screenedBy',
            'rhu',
            'queueTicket',
            'session.assignedDoctor',
            'session.bhwCompanion',
        ]);

        return response()->json([
            'message' => 'Request screened successfully.',
            'data'    => new TelemedicineRequestResource($result),
        ]);
    }

    /**
     * DELETE /api/v1/telemedicine/requests/{telemedicineRequest}
     */
    public function destroy(
        Request $request,
        TelemedicineRequest $telemedicineRequest
    ): JsonResponse {
        $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ]);

        $telemedicineRequest->update([
            'status'              => 'cancelled',
            'cancellation_reason' => $request->cancellation_reason,
            'cancelled_at'        => now(),
        ]);

        return response()->json([
            'message' => 'Telemedicine request cancelled.',
        ]);
    }

    /**
     * GET /api/v1/telemedicine/requests/mine
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $resident = $request->user()->residentProfile;

        abort_if(!$resident, 404, 'Resident profile not found.');

        $requests = TelemedicineRequest::with([
                'rhu',
                'queueTicket',
                'session.assignedDoctor',
                'session.bhwCompanion',
            ])
            ->where('resident_profile_id', $resident->id)
            ->latest()
            ->paginate(15);

        return TelemedicineRequestResource::collection($requests);
    }
}