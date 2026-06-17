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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

        $requests = TelemedicineRequest::with([
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
            ->when($rhuId, fn ($query) => $query->forRhu((int) $rhuId))
            ->when(
                $request->filled('status') && $request->status !== 'all',
                fn ($query) => $query->where('status', $request->status)
            )
            ->when(
                $request->filled('urgency_level'),
                fn ($query) => $query->where('urgency_level', $request->urgency_level)
            )
            ->when(
                $request->filled('date'),
                fn ($query) => $query->whereDate('created_at', $request->date)
            )
            ->latest()
            ->paginate($request->integer('per_page', 50));

        return TelemedicineRequestResource::collection($requests);
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