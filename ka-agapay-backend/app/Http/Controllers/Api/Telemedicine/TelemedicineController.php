<?php
// app/Http/Controllers/Api/Telemedicine/TelemedicineController.php

namespace App\Http\Controllers\Api\Telemedicine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Telemedicine\CreateTelemedicineRequestRequest;
use App\Http\Requests\Telemedicine\ScreenTelemedicineRequestRequest;
use App\Http\Requests\Telemedicine\ScheduleSessionRequest;
use App\Http\Requests\Telemedicine\UpdateSessionStatusRequest;
use App\Http\Requests\Telemedicine\SaveSessionNotesRequest;
use App\Http\Requests\Telemedicine\CreateReferralRequest;
use App\Http\Resources\Telemedicine\TelemedicineRequestResource;
use App\Http\Resources\Telemedicine\TelemedicineSessionResource;
use App\Http\Resources\Telemedicine\TelemedicineReferralResource;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Services\Telemedicine\TelemedicineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TelemedicineController extends Controller
{
    public function __construct(private readonly TelemedicineService $service) {}

    // =========================================================================
    // REQUESTS
    // =========================================================================

    /**
     * GET /api/v1/telemedicine/requests
     * Staff / MHO list of all telemedicine requests for an RHU.
     */
    public function indexRequests(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TelemedicineRequest::class);

        $request->validate([
            'rhu_id'       => ['required', 'integer', 'exists:barangays,barangay_id'],
            'status'       => ['nullable', 'string', 'in:pending,screened,scheduled,rejected,cancelled,completed'],
            'urgency_level'=> ['nullable', 'string', 'in:routine,urgent,emergency'],
            'date'         => ['nullable', 'date', 'date_format:Y-m-d'],
            'per_page'     => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $requests = TelemedicineRequest::with(['residentProfile.user', 'residentProfile.barangay', 'rhu', 'screenedBy', 'session'])
            ->forRhu($request->integer('rhu_id'))
            ->when(fn() => $request->filled('status'),        fn($q) => $q->where('status', $request->status))
            ->when(fn() => $request->filled('urgency_level'), fn($q) => $q->where('urgency_level', $request->urgency_level))
            ->when(fn() => $request->filled('date'),          fn($q) => $q->whereDate('created_at', $request->date),
                                                      fn($q) => $q->whereDate('created_at', today()))
            ->orderByRaw("CASE urgency_level WHEN 'emergency' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return TelemedicineRequestResource::collection($requests);
    }

    /**
     * POST /api/v1/telemedicine/requests
     * Resident or BHW submits a new telemedicine request.
     */
    public function createRequest(CreateTelemedicineRequestRequest $request): JsonResponse
    {
        $telRequest = $this->service->createRequest($request->validated());

        return response()->json([
            'message' => 'Telemedicine request submitted successfully.',
            'data'    => new TelemedicineRequestResource($telRequest),
        ], 201);
    }

    /**
     * GET /api/v1/telemedicine/requests/{request}
     * Show a single telemedicine request with full detail.
     */
    public function showRequest(TelemedicineRequest $request): JsonResponse
    {
        $this->authorize('view', $request);

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
            'session.notes.recordedBy',
            'session.referrals.issuedBy',
        ]);

        return response()->json(['data' => new TelemedicineRequestResource($request)]);
    }

    /**
     * PATCH /api/v1/telemedicine/requests/{request}/screen
     * Staff approves or rejects a pending request.
     */
    public function screenRequest(ScreenTelemedicineRequestRequest $httpRequest, TelemedicineRequest $request): JsonResponse
    {
        $this->authorize('screen', $request);

        $result = $this->service->screenRequest($request, $httpRequest->validated());

        return response()->json([
            'message' => $httpRequest->decision === 'approve'
                ? 'Request approved and marked as screened.'
                : 'Request rejected.',
            'data' => new TelemedicineRequestResource($result),
        ]);
    }

    /**
     * GET /api/v1/telemedicine/requests/mine
     * Resident views their own requests.
     */
    public function myRequests(Request $request): AnonymousResourceCollection
    {
        $resident = $request->user()->residentProfile;

        if (!$resident) {
            abort(404, 'No resident profile linked to your account.');
        }

        $requests = TelemedicineRequest::with(['rhu', 'session'])
            ->where('resident_profile_id', $resident->id)
            ->latest()
            ->paginate(15);

        return TelemedicineRequestResource::collection($requests);
    }

    /**
     * DELETE /api/v1/telemedicine/requests/{request}
     * Resident or admin cancels a request.
     */
    public function cancelRequest(Request $request, TelemedicineRequest $telRequest): JsonResponse
    {
        $this->authorize('cancel', $telRequest);

        $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ]);

        if ($telRequest->isTerminal()) {
            return response()->json(['message' => 'This request is already in a terminal state.'], 422);
        }

        $telRequest->update([
            'status'               => 'cancelled',
            'cancellation_reason'  => $request->cancellation_reason,
            'cancelled_at'         => now(),
        ]);

        return response()->json(['message' => 'Telemedicine request cancelled.', 'data' => new TelemedicineRequestResource($telRequest)]);
    }

    // =========================================================================
    // SESSIONS
    // =========================================================================

    /**
     * POST /api/v1/telemedicine/requests/{request}/session
     * Staff creates and schedules a session for a screened request.
     */
    public function createSession(ScheduleSessionRequest $httpRequest, TelemedicineRequest $request): JsonResponse
    {
        $this->authorize('createSession', $request);

        $session = $this->service->createSession($request, $httpRequest->validated());

        return response()->json([
            'message' => 'Telemedicine session scheduled successfully.',
            'data'    => new TelemedicineSessionResource($session),
        ], 201);
    }

    /**
     * GET /api/v1/telemedicine/sessions/{session}
     * Full detail of a session.
     */
    public function showSession(TelemedicineSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $session->load([
            'request.residentProfile.user',
            'request.residentProfile.barangay',
            'assignedDoctor',
            'bhwCompanion',
            'notes.recordedBy',
            'referrals.issuedBy',
        ]);

        return response()->json(['data' => new TelemedicineSessionResource($session)]);
    }

    /**
     * PATCH /api/v1/telemedicine/sessions/{session}/status
     * Transition a session status (start, pause, end, no-show, cancel).
     */
    public function updateSessionStatus(UpdateSessionStatusRequest $request, TelemedicineSession $session): JsonResponse
    {
        $this->authorize('updateStatus', $session);

        $session = $this->service->transitionSessionStatus($session, $request->status, $request->validated());

        return response()->json([
            'message' => "Session status updated to [{$request->status}].",
            'data'    => new TelemedicineSessionResource($session),
        ]);
    }

    /**
     * GET /api/v1/telemedicine/sessions
     * Doctor's scheduled sessions list (today or by date).
     */
    public function mySessions(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'date'   => ['nullable', 'date', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string'],
        ]);

        $sessions = TelemedicineSession::with(['request.residentProfile.user', 'request.rhu'])
            ->where('assigned_doctor_id', $request->user()->user_id)
            ->when(
                fn() => $request->filled('date'),
                fn($q) => $q->whereDate('scheduled_date', $request->date),
                fn($q) => $q->whereDate('scheduled_date', today())
            )
            ->when(fn() => $request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->orderBy('scheduled_time')
            ->paginate(20);

        return TelemedicineSessionResource::collection($sessions);
    }

    // =========================================================================
    // SESSION NOTES
    // =========================================================================

    /**
     * PUT /api/v1/telemedicine/sessions/{session}/notes
     * Doctor saves or updates SOAP notes for a session.
     */
    public function saveNotes(SaveSessionNotesRequest $request, TelemedicineSession $session): JsonResponse
    {
        $this->authorize('saveNotes', $session);

        $notes = $this->service->saveNotes($session, $request->validated());

        return response()->json([
            'message' => $request->boolean('finalize') ? 'Session notes finalized.' : 'Session notes saved.',
            'data'    => $notes,
        ]);
    }

    // =========================================================================
    // REFERRALS
    // =========================================================================

    /**
     * POST /api/v1/telemedicine/sessions/{session}/referrals
     * Doctor issues a referral from a session.
     */
    public function createReferral(CreateReferralRequest $request, TelemedicineSession $session): JsonResponse
    {
        $this->authorize('createReferral', $session);

        $referral = $this->service->createReferral($session, $request->validated());

        return response()->json([
            'message' => 'Referral issued successfully.',
            'data'    => new TelemedicineReferralResource($referral),
        ], 201);
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    /**
     * GET /api/v1/telemedicine/summary
     * Admin / MHO daily telemedicine summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewSummary', TelemedicineRequest::class);

        $request->validate([
            'rhu_id' => ['required', 'integer', 'exists:barangays,barangay_id'],
            'date'   => ['nullable', 'date', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $this->service->getDailySummary(
                $request->integer('rhu_id'),
                $request->date
            ),
        ]);
    }
}