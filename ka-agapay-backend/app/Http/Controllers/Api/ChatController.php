<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatLog;
use App\Services\Ai\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(private readonly GeminiService $gemini) {}

    /**
     * POST /api/v1/chat/message
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $message = $request->message;
        $history = $request->history ?? [];

        // Save user message
        ChatLog::create([
            'user_id' => $user?->user_id,
            'role'    => 'user',
            'message' => $message,
        ]);

        // Get AI response
        $start = microtime(true);
        $reply = $this->gemini->chat($message, $history);
        $duration = (int) ((microtime(true) - $start) * 1000);

        // Save AI response
        ChatLog::create([
            'user_id'     => $user?->user_id,
            'role'        => 'assistant',
            'message'     => $reply,
            'response_ms' => $duration,
        ]);

        return response()->json([
            'reply' => $reply,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $logs = ChatLog::where('user_id', $user->user_id)
            ->latest()
            ->limit(50)
            ->get()
            ->reverse()
            ->values();

        return response()->json(['data' => $logs]);
    }
}
