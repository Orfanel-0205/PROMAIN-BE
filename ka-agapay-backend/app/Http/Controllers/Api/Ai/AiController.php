<?php
// app/Http/Controllers/Api/Ai/AiController.php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\AiTriageScore;
use App\Models\TelemedicineRequest;
use App\Models\QueueTicket;
use App\Services\Ai\AiTriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(
        private readonly AiTriageService $aiService
    ) {}

    /**
     * POST /api/v1/ai/triage/telemedicine/{request}
     * Score a telemedicine request for urgency.
     */
    public function triageTelemedicine(
        Request $request,
        TelemedicineRequest $telRequest
    ): JsonResponse {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin']),
            403
        );

        $score = $this->aiService->scoreTelemedicineRequest($telRequest);

        return response()->json([
            'message' => 'Triage scoring complete.',
            'data'    => [
                'ai_score'             => $score->ai_score,
                'recommended_urgency'  => $score->recommended_urgency,
                'contributing_factors' => $score->contributing_factors,
                'confidence'           => $score->confidence,
                'declared_urgency'     => $telRequest->urgency_level,
                'urgency_mismatch'     => $score->recommended_urgency !== $telRequest->urgency_level,
                'processing_time_ms'   => $score->aiRequest->processing_time_ms,
            ],
        ]);
    }

    /**
     * POST /api/v1/ai/triage/queue/{ticket}
     * Score a queue ticket for AI-enhanced priority.
     */
    public function triageQueue(Request $request, QueueTicket $ticket): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin']),
            403
        );

        $score = $this->aiService->scoreQueueTicket($ticket);

        return response()->json([
            'message' => 'Queue triage scoring complete.',
            'data'    => [
                'ai_score'             => $score->ai_score,
                'recommended_urgency'  => $score->recommended_urgency,
                'contributing_factors' => $score->contributing_factors,
                'confidence'           => $score->confidence,
                'current_priority'     => $ticket->priority_score,
                'ai_adjustment'        => $score->ai_score - $ticket->priority_score,
            ],
        ]);
    }

    /**
     * GET /api/v1/ai/history
     * View AI request history — for thesis analytics.
     */
    public function history(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin']),
            403
        );

        $request->validate([
            'request_type' => ['nullable', 'string'],
            'status'       => ['nullable', 'string'],
            'per_page'     => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $history = AiRequest::with(['triageScore'])
            ->when($request->filled('request_type'),
                fn($q) => $q->where('request_type', $request->request_type))
            ->when($request->filled('status'),
                fn($q) => $q->where('status', $request->status))
            ->latest('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($history);
    }

    /**
     * PATCH /api/v1/ai/triage/{score}/override
     * Doctor overrides an AI recommendation — logged for model improvement.
     */
    public function override(Request $request, AiTriageScore $score): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin']),
            403
        );

        $validated = $request->validate([
            'override_reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $score->update([
            'doctor_overrode' => true,
            'override_reason' => $validated['override_reason'],
        ]);

        return response()->json([
            'message' => 'AI recommendation override recorded.',
            'data'    => $score->fresh(),
        ]);
    }
}