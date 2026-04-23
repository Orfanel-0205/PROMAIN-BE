<?php
// app/Http/Controllers/Api/ReferralController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Referral\CreateReferralRequest;
use App\Http\Requests\Referral\UpdateReferralStatusRequest;
use App\Http\Resources\Referral\ReferralResource;
use App\Models\Referral;
use App\Services\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(
        private readonly ReferralService $service
    ) {}

    /**
     * GET /api/v1/referrals
     * Staff/admin list — filterable.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin', 'bhw']),
            403
        );

        $request->validate([
            'status'    => ['nullable', 'string', 'in:' . implode(',', Referral::STATUSES)],
            'urgency'   => ['nullable', 'string', 'in:' . implode(',', Referral::URGENCY)],
            'type'      => ['nullable', 'string', 'in:' . implode(',', Referral::TYPES)],
            'bhw_id'    => ['nullable', 'integer'],
            'due_today' => ['nullable', 'boolean'],
            'per_page'  => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $referrals = Referral::with([
                'residentProfile.user',
                'issuedBy',
                'assignedBhw',
            ])
            ->when($request->filled('status'),
                fn($q) => $q->where('status', $request->status))
            ->when($request->filled('urgency'),
                fn($q) => $q->where('urgency', $request->urgency))
            ->when($request->filled('type'),
                fn($q) => $q->where('referral_type', $request->type))
            ->when($request->filled('bhw_id'),
                fn($q) => $q->forBhw($request->integer('bhw_id')))
            ->when($request->boolean('due_today'),
                fn($q) => $q->dueToday())
            ->orderByDesc('is_urgent')
            ->orderBy('follow_up_date')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json(
            ReferralResource::collection($referrals)->response()->getData()
        );
    }

    /**
     * POST /api/v1/referrals
     * Issue a new referral.
     */
    public function store(CreateReferralRequest $request): JsonResponse
    {
        $referral = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Referral created successfully.',
            'data'    => new ReferralResource($referral),
        ], 201);
    }

    /**
     * GET /api/v1/referrals/{referral}
     */
    public function show(Request $request, Referral $referral): JsonResponse
    {
        $this->authorizeView($request, $referral);

        $referral->load([
            'residentProfile.user',
            'issuedBy',
            'acknowledgedBy',
            'assignedBhw',
            'updates.updatedBy',
        ]);

        return response()->json(['data' => new ReferralResource($referral)]);
    }

    /**
     * PATCH /api/v1/referrals/{referral}/status
     * Transition referral status.
     */
    public function updateStatus(
        UpdateReferralStatusRequest $request,
        Referral $referral
    ): JsonResponse {
        if ($referral->isTerminal()) {
            return response()->json([
                'message' => "Referral is already in terminal state [{$referral->status}].",
            ], 422);
        }

        $referral = $this->service->transition($referral, $request->status, $request->validated());

        return response()->json([
            'message' => "Referral status updated to [{$request->status}].",
            'data'    => new ReferralResource($referral),
        ]);
    }

    /**
     * POST /api/v1/referrals/{referral}/bhw-report
     * BHW submits a monitoring update.
     */
    public function bhwReport(Request $request, Referral $referral): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['bhw', 'mho', 'super_admin']),
            403
        );

        $validated = $request->validate([
            'notes'    => ['required', 'string', 'min:10', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $update = $this->service->addBhwReport(
            $referral,
            $validated['notes'],
            $validated['metadata'] ?? []
        );

        return response()->json([
            'message' => 'BHW monitoring report submitted.',
            'data'    => $update,
        ], 201);
    }

    /**
     * GET /api/v1/referrals/mine
     * Resident views their own referrals.
     */
    public function mine(Request $request): JsonResponse
    {
        $resident = $request->user()->residentProfile;
        abort_unless($resident, 404, 'No resident profile found.');

        $referrals = Referral::with(['issuedBy', 'assignedBhw'])
            ->where('resident_profile_id', $resident->id)
            ->latest()
            ->paginate(15);

        return response()->json(
            ReferralResource::collection($referrals)->response()->getData()
        );
    }

    /**
     * GET /api/v1/referrals/bhw-assigned
     * BHW views referrals assigned to them.
     */
    public function bhwAssigned(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['bhw', 'mho', 'super_admin']),
            403
        );

        $referrals = Referral::with(['residentProfile.user', 'issuedBy'])
            ->forBhw($request->user()->user_id)
            ->open()
            ->orderByDesc('is_urgent')
            ->orderBy('follow_up_date')
            ->paginate(20);

        return response()->json(
            ReferralResource::collection($referrals)->response()->getData()
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function authorizeView(Request $request, Referral $referral): void
    {
        $user = $request->user();

        if ($user->hasAnyRole(['mho', 'super_admin', 'staff_admin'])) return;

        if ($user->hasRole('bhw') && $referral->assigned_bhw_id === $user->user_id) return;

        abort_unless(
            $user->residentProfile?->id === $referral->resident_profile_id,
            403,
            'You do not have permission to view this referral.'
        );
    }
}
