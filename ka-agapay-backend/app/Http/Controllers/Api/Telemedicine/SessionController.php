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
     * GET /api/v1/telemedicine/sessions
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $sessions = TelemedicineSession::with([
                'request.residentProfile.user',
                'request.residentProfile.barangay',
                'request.rhu',
                'assignedDoctor',
                'bhwCompanion',
                'notes.recordedBy',
                'referrals',
            ])
            ->where(function ($query) use ($user) {
                $query->where('assigned_doctor_id', $user->user_id)
                    ->orWhereHas('request', function ($requestQuery) use ($user) {
                        $requestQuery->where('requested_by', $user->user_id)
                            ->orWhereHas('residentProfile', function ($profileQuery) use ($user) {
                                $profileQuery->where('user_id', $user->user_id);
                            });
                    });
            })
            ->latest()
            ->paginate(20);

        return TelemedicineSessionResource::collection($sessions);
    }

    /**
     * POST /api/v1/telemedicine/requests/{telemedicineRequest}/session
     */
    public function store(
        ScheduleSessionRequest $request,
        TelemedicineRequest $telemedicineRequest
    ): JsonResponse {
        $session = $this->service->createSession(
            $telemedicineRequest,
            $request->validated()
        );

        $session->load([
            'request.residentProfile.user',
            'request.rhu',
            'assignedDoctor',
            'bhwCompanion',
            'notes',
            'referrals',
        ]);

        return response()->json([
            'message' => 'Telemedicine session scheduled.',
            'data'    => new TelemedicineSessionResource($session),
        ], 201);
    }

    /**
     * GET /api/v1/telemedicine/sessions/{session}
     */
    public function show(TelemedicineSession $session): JsonResponse
    {
        $session->load([
            'request.residentProfile.user',
            'request.residentProfile.barangay',
            'request.rhu',
            'assignedDoctor',
            'bhwCompanion',
            'notes.recordedBy',
            'referrals',
        ]);

        return response()->json([
            'data' => new TelemedicineSessionResource($session),
        ]);
    }

    /**
     * PATCH /api/v1/telemedicine/sessions/{session}/status
     */
    public function updateStatus(
        UpdateSessionStatusRequest $request,
        TelemedicineSession $session
    ): JsonResponse {
        $session = $this->service->transitionSessionStatus(
            $session,
            $request->status,
            $request->validated()
        );

        $session->load([
            'request.residentProfile.user',
            'request.rhu',
            'assignedDoctor',
            'bhwCompanion',
            'notes',
            'referrals',
        ]);

        return response()->json([
            'message' => 'Session status updated.',
            'data'    => new TelemedicineSessionResource($session),
        ]);
    }

    /**
     * PUT /api/v1/telemedicine/sessions/{session}/notes
     */
    public function saveNotes(
        SaveSessionNotesRequest $request,
        TelemedicineSession $session
    ): JsonResponse {
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
     * GET /api/v1/telemedicine/sessions/mine
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $sessions = TelemedicineSession::with([
                'request.residentProfile.user',
                'request.rhu',
                'assignedDoctor',
                'bhwCompanion',
            ])
            ->where('assigned_doctor_id', $request->user()->user_id)
            ->latest()
            ->paginate(20);

        return TelemedicineSessionResource::collection($sessions);
    }
}