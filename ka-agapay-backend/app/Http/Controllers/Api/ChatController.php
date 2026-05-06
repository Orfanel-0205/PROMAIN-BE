<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatLog;
use App\Services\Ai\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(private readonly GeminiService $gemini) {}

    /**
     * POST /api/v1/chat/message
     *
     * Returns shape matching frontend ChatResponse type:
     * {
     *   message: { id, role, content, timestamp },
     *   detected_complaint?: string,
     *   suggested_action?: 'book_appointment' | 'view_records' | null
     * }
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['nullable', 'array'],
        ]);

        $user    = $request->user();
        $message = $request->message;
        $history = $request->history ?? [];

        // Save user message
        ChatLog::create([
            'user_id' => $user?->user_id,
            'role'    => 'user',
            'message' => $message,
        ]);

        // Get AI response
        $start    = microtime(true);
        $reply    = $this->gemini->chat($message, $history);
        $duration = (int) ((microtime(true) - $start) * 1000);

        // Save AI response
        $log = ChatLog::create([
            'user_id'     => $user?->user_id,
            'role'        => 'assistant',
            'message'     => $reply,
            'response_ms' => $duration,
        ]);

        // Detect chief complaint keywords (simple heuristic)
        $complaintKeywords = [
            'sakit', 'masakit', 'lagnat', 'ubo', 'sipon', 'hirap', 'sugat',
            'dugo', 'nahilo', 'nahihilo', 'pananakit', 'pagduduwal', 'pagtatae',
            'pain', 'fever', 'cough', 'cold', 'dizzy', 'wound', 'bleeding',
            'headache', 'stomachache', 'vomiting', 'diarrhea', 'rash',
        ];

        $detectedComplaint = null;
        $lowerMessage = strtolower($message);
        foreach ($complaintKeywords as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                $detectedComplaint = $message;
                break;
            }
        }

        // Detect if AI reply suggests booking or records
        $suggestedAction = null;
        $lowerReply = strtolower($reply);
        if (
            str_contains($lowerReply, 'appointment') ||
            str_contains($lowerReply, 'konsultasyon') ||
            str_contains($lowerReply, 'doctor') ||
            str_contains($lowerReply, 'doktor') ||
            str_contains($lowerReply, 'schedule')
        ) {
            $suggestedAction = 'book_appointment';
        } elseif (
            str_contains($lowerReply, 'record') ||
            str_contains($lowerReply, 'rekord') ||
            str_contains($lowerReply, 'history') ||
            str_contains($lowerReply, 'kasaysayan')
        ) {
            $suggestedAction = 'view_records';
        }

        return response()->json([
            // ✅ Matches frontend ChatResponse type exactly
            'message' => [
                'id'        => (string) ($log->id ?? Str::uuid()),
                'role'      => 'assistant',
                'content'   => $reply,
                'timestamp' => now()->toISOString(),
            ],
            'detected_complaint' => $detectedComplaint,
            'suggested_action'   => $suggestedAction,
        ]);
    }

    /**
     * GET /api/v1/chat/history
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $logs = ChatLog::where('user_id', $user->user_id)
            ->latest()
            ->limit(50)
            ->get()
            ->reverse()
            ->values()
            ->map(fn($log) => [
                'id'        => (string) $log->id,
                'role'      => $log->role,
                'content'   => $log->message,
                'timestamp' => $log->created_at->toISOString(),
            ]);

        return response()->json(['data' => $logs]);
    }

    /**
     * POST /api/v1/chat/end
     */
    public function endSession(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Session ended.']);
    }

    /**
     * POST /api/v1/chat/escalate
     */
    public function escalateToDoctor(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Escalated to doctor.']);
    }
}