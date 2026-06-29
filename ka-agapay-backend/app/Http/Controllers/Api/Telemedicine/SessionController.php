<?php
// app/Http/Controllers/Api/Telemedicine/SessionController.php

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
    ) {
    }

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
                'consultation',
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
            'request.residentProfile.barangay',
            'request.rhu',
            'assignedDoctor',
            'bhwCompanion',
            'notes',
            'referrals',
            'consultation',
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
            'consultation',
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
            (string) $request->input('status'),
            $request->validated()
        );

        $session->load([
            'request.residentProfile.user',
            'request.residentProfile.barangay',
            'request.rhu',
            'assignedDoctor',
            'bhwCompanion',
            'notes',
            'referrals',
            'consultation',
        ]);

        return response()->json([
            'message' => 'Session status updated.',
            'data'    => new TelemedicineSessionResource($session),
        ]);
    }

    /**
     * POST /api/v1/telemedicine/sessions/{session}/notify-patient
     *
     * RHU staff action: tells the resident the room is ready to join. Creates a
     * stored notification + sends a push so the resident can tap to enter the
     * SAME room. Jitsi itself never notifies the resident, so this is required.
     */
    public function notifyPatient(Request $request, TelemedicineSession $session): JsonResponse
    {
        $session->loadMissing(['request.residentProfile.user', 'request.rhu']);

        $result = app(\App\Services\Notification\NotificationService::class)
            ->notifyTelemedicineCalling($session);

        return response()->json([
            'message' => ($result['database_created'] ?? false)
                ? 'The patient has been notified that the telemedicine room is ready.'
                : ($result['message'] ?? 'Could not notify the patient.'),
            'notified' => (bool) ($result['database_created'] ?? false),
            'push_sent' => (bool) ($result['push_sent'] ?? false),
        ]);
    }

    /**
     * PATCH /api/v1/telemedicine/sessions/{session}/end
     *
     * Single source of truth for ending a telemedicine call from the room.
     *
     * finalize = false -> save SOAP draft + end video, keep consultation editable.
     * finalize = true  -> finalize SOAP and complete consultation, appointment,
     *                     telemedicine request and session together.
     */
    public function end(
        Request $request,
        TelemedicineSession $session
    ): JsonResponse {
        $validated = $request->validate([
            'finalize'         => ['nullable', 'boolean'],
            'soap'             => ['nullable', 'array'],
            'soap.subjective'  => ['nullable', 'string'],
            'soap.objective'   => ['nullable', 'string'],
            'soap.assessment'  => ['nullable', 'string'],
            'soap.plan'        => ['nullable', 'string'],
            'soap.diagnosis'   => ['nullable', 'string'],
            'soap.treatment'   => ['nullable', 'string'],
            'soap.notes'       => ['nullable', 'string'],
        ]);

        $finalize = (bool) ($validated['finalize'] ?? false);
        $soap = $validated['soap'] ?? [];

        try {
            $session = $this->service->endSessionWithSoap($session, $finalize, $soap);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to complete session. Please try again.',
            ], 500);
        }

        $consultationId = $session->consultation_id
            ?? $session->consultation?->id;

        $appointment = $session->request?->appointment_id
            ? \App\Models\Appointment::query()
                ->with(['resident', 'handler', 'rhu', 'consultation', 'queueTicket', 'telemedicineRequest.session'])
                ->find($session->request->appointment_id)
            : null;

        return response()->json([
            'message' => $finalize
                ? 'Telemedicine consultation completed.'
                : 'Telemedicine session ended. Continue SOAP documentation.',
            'telemedicine_session' => new TelemedicineSessionResource($session),
            'telemedicine_request' => $session->request,
            'consultation'         => $session->consultation,
            'appointment'          => $appointment,
            'redirect_to'          => 'consultation',
            'consultation_id'      => $consultationId,
            'can_finalize'         => !$finalize,
            'data'                 => new TelemedicineSessionResource($session),
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
                'request.residentProfile.barangay',
                'request.rhu',
                'assignedDoctor',
                'bhwCompanion',
                'notes',
                'referrals',
                'consultation',
            ])
            ->where('assigned_doctor_id', $request->user()->user_id)
            ->latest()
            ->paginate(20);

        return TelemedicineSessionResource::collection($sessions);
    }
}