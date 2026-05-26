<?php

namespace App\Http\Controllers\Api\Telemedicine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Telemedicine\SaveSessionNotesRequest;
use App\Http\Requests\Telemedicine\ScheduleSessionRequest;
use App\Http\Requests\Telemedicine\UpdateSessionStatusRequest;
use App\Http\Resources\Telemedicine\TelemedicineSessionResource;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Services\Telemedicine\TelemedicineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SessionController extends Controller
{
    public function __construct(
        private readonly TelemedicineService $service
    ) {}

    /**
     * POST /telemedicine/requests/{request}/session
     */
    public function store(
        ScheduleSessionRequest $request,
        TelemedicineRequest $telemedicineRequest
    ): JsonResponse {
        $this->authorize('createSession', $telemedicineRequest);

        $session = $this->service->createSession(
            $telemedicineRequest,
            $request->validated()
        );

        return response()->json([
            'message' => 'Telemedicine session scheduled.',
            'data'    => new TelemedicineSessionResource($session),
        ], 201);
    }

    /**
     * GET /telemedicine/sessions/{session}
     */
    public function show(TelemedicineSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $session->load([
            'request.residentProfile.user',
            'assignedDoctor',
            'notes.recordedBy',
            'referrals',
        ]);

        return response()->json([
            'data' => new TelemedicineSessionResource($session),
        ]);
    }

    /**
     * PATCH /telemedicine/sessions/{session}/status
     */
    public function updateStatus(
        UpdateSessionStatusRequest $request,
        TelemedicineSession $session
    ): JsonResponse {
        $this->authorize('updateStatus', $session);

        $session = $this->service->transitionSessionStatus(
            $session,
            $request->status,
            $request->validated()
        );

        return response()->json([
            'message' => 'Session status updated.',
            'data'    => new TelemedicineSessionResource($session),
        ]);
    }

    /**
     * PUT /telemedicine/sessions/{session}/notes
     */
    public function saveNotes(
        SaveSessionNotesRequest $request,
        TelemedicineSession $session
    ): JsonResponse {
        $this->authorize('saveNotes', $session);

        $notes = $this->service->saveNotes(
            $session,
            $request->validated()
        );

        return response()->json([
            'message' => 'Session notes saved.',
            'data'    => $notes,
        ]);
    }

    /**
     * GET /telemedicine/sessions/mine
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $sessions = TelemedicineSession::with([
                'request.residentProfile.user',
                'request.rhu',
            ])
            ->where('assigned_doctor_id', $request->user()->user_id)
            ->latest()
            ->paginate(20);

        return TelemedicineSessionResource::collection($sessions);
    }
}