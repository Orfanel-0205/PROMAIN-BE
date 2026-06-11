<?php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\Consultation;
use App\Models\QueueTicket;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Services\Ai\AiTriageService;
use App\Services\Ai\ClinicalSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiController extends Controller
{
    public function __construct(
        private readonly AiTriageService $aiService,
        private readonly ClinicalSummaryService $summaryService,
    ) {}

    public function triageTelemedicine(Request $request, int $id): JsonResponse
    {
        $this->authorizeAi($request);

        $telRequest = TelemedicineRequest::findOrFail($id);
        $score = $this->aiService->scoreTelemedicineRequest($telRequest);

        return response()->json([
            'message' => 'Triage scoring complete.',
            'data' => [
                'ai_score' => $score->ai_score,
                'recommended_urgency' => $score->recommended_urgency,
                'contributing_factors' => $score->contributing_factors,
                'confidence' => $score->confidence,
                'declared_urgency' => $telRequest->urgency_level,
                'urgency_mismatch' => $score->recommended_urgency !== $telRequest->urgency_level,
                'processing_time_ms' => $score->aiRequest->processing_time_ms ?? null,
            ],
        ]);
    }

    public function triageQueue(Request $request, int $id): JsonResponse
    {
        $this->authorizeAi($request);

        $ticket = QueueTicket::findOrFail($id);
        $score = $this->aiService->scoreQueueTicket($ticket);

        return response()->json([
            'message' => 'Queue triage scoring complete.',
            'data' => [
                'ai_score' => $score->ai_score,
                'recommended_urgency' => $score->recommended_urgency,
                'contributing_factors' => $score->contributing_factors,
                'confidence' => $score->confidence,
                'current_priority' => $ticket->priority_score,
                'ai_adjustment' => $score->ai_score - (int) $ticket->priority_score,
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorizeAi($request);

        if (!Schema::hasTable('ai_requests')) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0],
            ]);
        }

        $request->validate([
            'request_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $history = AiRequest::query()
            ->when(
                $request->filled('request_type'),
                fn ($q) => $q->where('request_type', $request->request_type)
            )
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->status)
            )
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($history);
    }

    public function override(Request $request, int $id): JsonResponse
    {
        $this->authorizeAi($request);

        if (!Schema::hasTable('ai_triage_scores')) {
            return response()->json([
                'message' => 'AI triage table is not available.',
            ], 404);
        }

        $validated = $request->validate([
            'final_urgency' => ['required', 'string', 'max:50'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::table('ai_triage_scores')
            ->where('id', $id)
            ->update([
                'final_urgency' => $validated['final_urgency'],
                'override_reason' => $validated['override_reason'] ?? null,
                'overridden_by' => $request->user()->user_id ?? $request->user()->id,
                'overridden_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'AI recommendation override saved.',
        ]);
    }

    /**
     * POST /api/v1/ai/summarize-consultation/{id}
     */
    public function summarizeConsultation(Request $request, int $id): JsonResponse
    {
        $this->authorizeClinical($request);

        $consultation = Consultation::findOrFail($id);

        $validated = $request->validate([
            'transcript' => ['nullable', 'string'],
            'save_to_soap' => ['nullable', 'boolean'],
        ]);

        $summary = $this->summaryService->summarize([
            'transcript' => $validated['transcript'] ?? null,
            'chief_complaint' => $consultation->chief_complaint ?? null,
            'subjective' => $consultation->subjective ?? null,
            'objective' => $consultation->objective ?? null,
            'assessment' => $consultation->assessment ?? null,
            'plan' => $consultation->plan ?? null,
            'diagnosis' => $consultation->diagnosis ?? null,
            'treatment' => $consultation->treatment ?? null,
            'notes' => $consultation->notes ?? null,
        ]);

        $updates = [];

        if (Schema::hasColumn('consultations', 'ai_summary')) {
            $updates['ai_summary'] = $summary['summary'] ?? null;
        }

        if (Schema::hasColumn('consultations', 'ai_summary_payload')) {
            $updates['ai_summary_payload'] = json_encode($summary);
        }

        if (Schema::hasColumn('consultations', 'ai_summary_generated_at')) {
            $updates['ai_summary_generated_at'] = now();
        }

        if (Schema::hasColumn('consultations', 'transcript') && !empty($validated['transcript'])) {
            $updates['transcript'] = $validated['transcript'];
        }

        if ($request->boolean('save_to_soap', true)) {
            foreach (['subjective', 'objective', 'assessment', 'plan', 'diagnosis'] as $field) {
                if (Schema::hasColumn('consultations', $field) && !empty($summary[$field])) {
                    $updates[$field] = $summary[$field];
                }
            }

            if (Schema::hasColumn('consultations', 'treatment') && !empty($summary['treatment'])) {
                $updates['treatment'] = $summary['treatment'];
            } elseif (Schema::hasColumn('consultations', 'treatment') && !empty($summary['plan'])) {
                $updates['treatment'] = $summary['plan'];
            }
        }

        if (!empty($updates)) {
            $consultation->update($updates);
        }

        $freshConsultation = $consultation->fresh();

        $this->logAiRequest('consultation_summary', $request, $summary);

        return response()->json([
            'message' => 'AI consultation summary generated.',
            'data' => $summary,
            'consultation' => $freshConsultation,
        ]);
    }

    /**
     * POST /api/v1/ai/summarize-telemedicine-session/{id}
     */
    public function summarizeTelemedicineSession(Request $request, int $id): JsonResponse
    {
        $this->authorizeClinical($request);

        $session = TelemedicineSession::findOrFail($id);

        $validated = $request->validate([
            'transcript' => ['nullable', 'string'],
            'chief_complaint' => ['nullable', 'string', 'max:1000'],
        ]);

        $chiefComplaint = $validated['chief_complaint'] ?? null;

        $summary = $this->summaryService->summarize([
            'transcript' => $validated['transcript'] ?? null,
            'chief_complaint' => $chiefComplaint,
        ]);

        if (Schema::hasTable('telemedicine_session_notes')) {
            DB::table('telemedicine_session_notes')->insert([
                'session_id' => $session->id,
                'recorded_by' => $request->user()->user_id ?? $request->user()->id,
                'subjective' => $summary['subjective'] ?? 'Not stated',
                'objective' => $summary['objective'] ?? 'Not stated',
                'assessment' => $summary['assessment'] ?? 'Not stated',
                'plan' => $summary['plan'] ?? 'Not stated',
                'is_finalized' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->logAiRequest('telemedicine_summary', $request, $summary);

        return response()->json([
            'message' => 'AI telemedicine summary generated.',
            'data' => $summary,
            'session' => $session->fresh(),
        ]);
    }

    /**
     * POST /api/v1/ai/summarize-events
     */
    public function summarizeEvents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
        ]);

        $text = trim(($validated['title'] ?? '') . "\n" . $validated['description']);
        $summary = Str::limit(preg_replace('/\s+/', ' ', $text) ?? $text, 155, '...');

        return response()->json([
            'message' => 'Event SMS summary generated.',
            'data' => [
                'sms_summary' => $summary,
                'character_count' => strlen($summary),
            ],
        ]);
    }

    private function authorizeAi(Request $request): void
    {
        $user = $request->user();

        if (method_exists($user, 'hasAnyRole')) {
            abort_unless(
                $user->hasAnyRole([
                    'mho',
                    'super_admin',
                    'admin',
                    'rhu_admin',
                    'staff',
                    'doctor',
                    'nurse',
                    'midwife',
                ]),
                403
            );
        }
    }

    private function authorizeClinical(Request $request): void
    {
        $user = $request->user();

        if (method_exists($user, 'hasAnyRole')) {
            abort_unless(
                $user->hasAnyRole([
                    'mho',
                    'super_admin',
                    'admin',
                    'rhu_admin',
                    'staff',
                    'doctor',
                    'nurse',
                    'midwife',
                ]),
                403
            );
        }
    }

    private function logAiRequest(string $type, Request $request, array $result): void
    {
        if (!Schema::hasTable('ai_requests')) {
            return;
        }

        try {
            DB::table('ai_requests')->insert([
                'user_id' => $request->user()->user_id ?? $request->user()->id ?? null,
                'request_type' => $type,
                'prompt' => $type,
                'response' => json_encode($result),
                'status' => 'completed',
                'processing_time_ms' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // Non-blocking AI audit trail only.
        }
    }
}