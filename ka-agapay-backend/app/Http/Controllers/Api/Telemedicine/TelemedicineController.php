<?php

namespace App\Http\Controllers\Api\Telemedicine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Telemedicine\CreateTelemedicineRequestRequest;
use App\Http\Requests\Telemedicine\ScreenTelemedicineRequestRequest;
use App\Http\Resources\Telemedicine\TelemedicineRequestResource;
use App\Models\TelemedicineRequest;
use App\Services\Telemedicine\TelemedicineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TelemedicineController extends Controller
{
    public function __construct(
        private readonly TelemedicineService $service
    ) {}

    /**
     * GET /telemedicine/requests
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TelemedicineRequest::class);

        $request->validate([
            'rhu_id'        => ['required', 'integer', 'exists:barangays,barangay_id'],
            'status'        => ['nullable', 'string'],
            'urgency_level' => ['nullable', 'string'],
            'date'          => ['nullable', 'date'],
            'per_page'      => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $requests = TelemedicineRequest::with([
                'residentProfile.user',
                'residentProfile.barangay',
                'rhu',
                'screenedBy',
                'session',
            ])
            ->forRhu($request->integer('rhu_id'))
            ->when(
                $request->filled('status'),
                fn($q) => $q->where('status', $request->status)
            )
            ->when(
                $request->filled('urgency_level'),
                fn($q) => $q->where('urgency_level', $request->urgency_level)
            )
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return TelemedicineRequestResource::collection($requests);
    }

    /**
     * POST /telemedicine/requests
     */
    public function store(CreateTelemedicineRequestRequest $request): JsonResponse
    {
        $telemedicineRequest = $this->service->createRequest(
            $request->validated()
        );

        return response()->json([
            'message' => 'Telemedicine request submitted successfully.',
            'data'    => new TelemedicineRequestResource($telemedicineRequest),
        ], 201);
    }

    /**
     * GET /telemedicine/requests/{request}
     */
    public function show(TelemedicineRequest $request): JsonResponse
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
            'session',
        ]);

        return response()->json([
            'data' => new TelemedicineRequestResource($request),
        ]);
    }

    /**
     * PATCH /telemedicine/requests/{request}/screen
     */
    public function screen(
        ScreenTelemedicineRequestRequest $httpRequest,
        TelemedicineRequest $request
    ): JsonResponse {
        $this->authorize('screen', $request);

        $result = $this->service->screenRequest(
            $request,
            $httpRequest->validated()
        );

        return response()->json([
            'message' => 'Request screened successfully.',
            'data'    => new TelemedicineRequestResource($result),
        ]);
    }

    /**
     * DELETE /telemedicine/requests/{request}
     */
    public function destroy(
        Request $request,
        TelemedicineRequest $telemedicineRequest
    ): JsonResponse {
        $this->authorize('cancel', $telemedicineRequest);

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
     * GET /telemedicine/requests/mine
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $resident = $request->user()->residentProfile;

        abort_if(!$resident, 404, 'Resident profile not found.');

        $requests = TelemedicineRequest::with(['rhu', 'session'])
            ->where('resident_profile_id', $resident->id)
            ->latest()
            ->paginate(15);

        return TelemedicineRequestResource::collection($requests);
    }
}