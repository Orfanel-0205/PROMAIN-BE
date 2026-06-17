<?php
// app/Http/Controllers/Api/ChatController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatLog;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\Ai\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(private readonly GeminiService $geminiService) {}

    /**
     * POST /api/v1/chat/message
     *
     * Supports both resident mobile chat and RHU admin/staff chat.
     * Each conversation is stored in its own chat_sessions row, similar to ChatGPT history.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'session_id' => ['nullable', 'string', 'max:120'],
            'history' => ['nullable', 'array'],
            'history.*.role' => ['nullable', 'string', 'in:user,assistant'],
            'history.*.content' => ['nullable', 'string', 'max:4000'],
            'audience' => ['nullable', 'string', 'in:resident,staff'],
            'source' => ['nullable', 'string', 'max:40'],
            'context' => ['nullable', 'array'],
        ]);

        $start = microtime(true);
        $user = $request->user();
        $message = trim($validated['message']);
        $audience = $this->resolveAudience($request);
        $language = $this->detectLanguage($message);
        $intent = $this->detectIntent($message, $audience);
        $suggestedAction = $this->suggestAction($message, $audience, $intent);

        $session = $this->resolveSession($request, $audience, $language);

        $userMessage = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'message' => $message,
            'language' => $language,
            'intent' => $intent,
            'created_at' => now(),
        ]);

        $history = $this->historyForAi($session->id, $userMessage->id);

        $context = $this->safeContext($validated['context'] ?? []);
        $context['audience'] = $audience;
        $context['source'] = $validated['source'] ?? ($audience === 'staff' ? 'admin' : 'mobile');

        $reply = $this->geminiService->chat($message, $history, $audience, $context);

        if ($audience === 'staff') {
            $reply = $this->normalizeStaffButtonLanguage($reply);
        }

        $responseMs = (int) ((microtime(true) - $start) * 1000);

        $assistantMessage = ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'message' => $reply,
            'language' => $language,
            'intent' => $intent,
            'suggested_action' => $suggestedAction,
            'response_time_ms' => $responseMs,
            'created_at' => now(),
        ]);

        $session->update([
            'title' => $session->title ?: $this->makeSessionTitle($message),
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        $this->mirrorToChatLogs($user?->user_id ?? $user?->id, $session, 'user', $message, $intent, $language, null);
        $this->mirrorToChatLogs($user?->user_id ?? $user?->id, $session, 'assistant', $reply, $intent, $language, $responseMs);

        return response()->json([
            'message' => $this->formatMessage($assistantMessage),
            'session_id' => $session->session_token,
            'audience' => $audience,
            'intent' => $intent,
            'suggested_action' => $suggestedAction,
            'tutorial_cards' => $audience === 'staff'
                ? $this->tutorialCards($suggestedAction, $intent)
                : [],
            'detected_complaint' => $audience === 'resident' ? $this->detectComplaint($message) : null,
            'meta' => [
                'response_ms' => $responseMs,
                'source' => config('services.google.gemini_api_key') || env('GEMINI_API_KEY')
                    ? 'gemini_or_rule_fallback'
                    : 'rule_fallback',
            ],
        ]);
    }

    /**
     * GET /api/v1/chat/history
     * - Without session_id: returns separate chat sessions.
     * - With session_id: returns messages inside that one chat only.
     */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['nullable', 'string', 'max:120'],
            'audience' => ['nullable', 'string', 'in:resident,staff'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $audience = $validated['audience'] ?? null;

        if (!empty($validated['session_id'])) {
            $session = $this->findOwnedSession($request, $validated['session_id'], $audience);

            if (!$session) {
                return response()->json([
                    'message' => 'Chat session not found.',
                    'data' => [],
                ], 404);
            }

            $messages = ChatMessage::query()
                ->where('chat_session_id', $session->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (ChatMessage $message) => $this->formatMessage($message))
                ->values();

            return response()->json([
                'data' => $messages,
                'session' => $this->formatSession($session),
            ]);
        }

        $perPage = (int) ($validated['per_page'] ?? 30);
        $user = $request->user();
        $userId = $user?->user_id ?? $user?->id;

        $sessions = ChatSession::query()
            ->where('user_id', $userId)
            ->when($audience, fn ($query) => $query->where('audience', $audience))
            ->where('status', '!=', 'deleted')
            ->withCount('messages')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('created_at')
            ->limit($perPage)
            ->get()
            ->map(fn (ChatSession $session) => $this->formatSession($session))
            ->values();

        return response()->json([
            'data' => $sessions,
        ]);
    }

    /**
     * POST /api/v1/chat/end
     * Ends the selected chat. The next sent message starts a new chat if session_id is null.
     */
    public function endSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['nullable', 'string', 'max:120'],
            'audience' => ['nullable', 'string', 'in:resident,staff'],
        ]);

        $session = !empty($validated['session_id'])
            ? $this->findOwnedSession($request, $validated['session_id'], $validated['audience'] ?? null)
            : $this->latestActiveSession($request, $validated['audience'] ?? null);

        if ($session) {
            $session->update([
                'status' => 'ended',
                'last_activity_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Chat session ended.',
        ]);
    }

    /**
     * DELETE /api/v1/chat/history/{sessionId}
     */
    public function destroySession(Request $request, string $sessionId): JsonResponse
    {
        $audience = $request->query('audience');
        $audience = in_array($audience, ['resident', 'staff'], true) ? $audience : null;

        $session = $this->findOwnedSession($request, $sessionId, $audience);

        if (!$session) {
            return response()->json([
                'message' => 'Chat session not found.',
            ], 404);
        }

        DB::transaction(function () use ($session) {
            ChatMessage::query()
                ->where('chat_session_id', $session->id)
                ->delete();

            $session->delete();
        });

        return response()->json([
            'message' => 'Chat history deleted.',
        ]);
    }

    /**
     * POST /api/v1/chat/escalate
     * Keeps old route compatible while giving a professional handoff response.
     */
    public function escalateToDoctor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $session = !empty($validated['session_id'])
            ? $this->findOwnedSession($request, $validated['session_id'])
            : $this->latestActiveSession($request);

        if ($session) {
            $session->update([
                'last_activity_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Escalation noted. Please route the concern to the assigned RHU clinician according to RHU protocol.',
        ]);
    }

    private function resolveSession(Request $request, string $audience, string $language): ChatSession
    {
        $sessionId = (string) $request->input('session_id', '');
        $barangayId = $this->resolveBarangayIdForChat($request);

        if ($sessionId !== '') {
            $existing = $this->findOwnedSession($request, $sessionId, $audience);

            if ($existing) {
                $updates = [];

                if (
                    Schema::hasColumn('chat_sessions', 'barangay_id') &&
                    $barangayId &&
                    (int) ($existing->barangay_id ?? 0) !== (int) $barangayId
                ) {
                    /*
                     * Important:
                     * Always correct the session barangay using the latest resident profile barangay.
                     * Do not keep the old/wrong barangay such as Abonagan.
                     */
                    $updates['barangay_id'] = $barangayId;
                }

                if (!empty($updates)) {
                    $existing->update($updates);
                    $existing->refresh();
                }

                return $existing;
            }
        }

        $user = $request->user();

        $payload = [
            'user_id' => $user?->user_id ?? $user?->id,
            'session_token' => (string) Str::uuid(),
            'audience' => $audience,
            'title' => null,
            'language' => $language,
            'status' => 'active',
            'last_activity_at' => now(),
        ];

        if (Schema::hasColumn('chat_sessions', 'barangay_id')) {
            $payload['barangay_id'] = $barangayId;
        }

        return ChatSession::create($payload);
    }

    private function resolveBarangayIdForChat(Request $request): ?int
    {
        $context = $request->input('context', []);

        if (is_array($context)) {
            $fromContext = $this->resolveBarangayIdFromContext($context);

            if ($fromContext) {
                return $fromContext;
            }
        }

        $user = $request->user();
        $userId = $user?->user_id ?? $user?->id;

        if (!$userId) {
            return null;
        }

        return $this->resolveBarangayIdFromUser((int) $userId);
    }

    private function resolveBarangayIdFromContext(array $context): ?int
    {
        if (!Schema::hasTable('barangays')) {
            return null;
        }

        $rawId = $context['barangay_id'] ?? null;

        if ($rawId !== null && $rawId !== '' && is_numeric($rawId)) {
            $barangayId = (int) $rawId;

            return DB::table('barangays')
                ->where('barangay_id', $barangayId)
                ->exists()
                    ? $barangayId
                    : null;
        }

        $name = trim((string) (
            $context['barangay']
            ?? $context['barangay_name']
            ?? ''
        ));

        if ($name === '') {
            return null;
        }

        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $name) ?: $name);

        $barangay = DB::table('barangays')
            ->select('barangay_id')
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();

        return $barangay ? (int) $barangay->barangay_id : null;
    }

    private function resolveBarangayIdFromUser(int $userId): ?int
    {
        /*
         * PRIORITY 1:
         * resident_profiles is the real resident profile source.
         * This must win over users.barangay_id.
         */
        if (
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'user_id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id')
        ) {
            $barangayId = DB::table('resident_profiles')
                ->where('user_id', $userId)
                ->value('barangay_id');

            if ($barangayId) {
                return (int) $barangayId;
            }
        }

        /*
         * PRIORITY 2:
         * fallback only.
         */
        if (
            Schema::hasTable('users') &&
            Schema::hasColumn('users', 'user_id') &&
            Schema::hasColumn('users', 'barangay_id')
        ) {
            $barangayId = DB::table('users')
                ->where('user_id', $userId)
                ->value('barangay_id');

            if ($barangayId) {
                return (int) $barangayId;
            }
        }

        return null;
    }

    private function findOwnedSession(Request $request, string $sessionId, ?string $audience = null): ?ChatSession
    {
        $user = $request->user();
        $userId = $user?->user_id ?? $user?->id;

        return ChatSession::query()
            ->where('user_id', $userId)
            ->where('status', '!=', 'deleted')
            ->when($audience, fn ($query) => $query->where('audience', $audience))
            ->where(function ($query) use ($sessionId) {
                $query->where('session_token', $sessionId);

                if (ctype_digit($sessionId)) {
                    $query->orWhere('id', (int) $sessionId);
                }
            })
            ->first();
    }

    private function latestActiveSession(Request $request, ?string $audience = null): ?ChatSession
    {
        $user = $request->user();
        $userId = $user?->user_id ?? $user?->id;

        return ChatSession::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->when($audience, fn ($query) => $query->where('audience', $audience))
            ->latest('last_activity_at')
            ->first();
    }

    private function historyForAi(int $sessionId, int $currentUserMessageId): array
    {
        return ChatMessage::query()
            ->where('chat_session_id', $sessionId)
            ->where('id', '<>', $currentUserMessageId)
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->sortBy('created_at')
            ->map(fn (ChatMessage $message) => [
                'role' => $message->role === 'assistant' ? 'assistant' : 'user',
                'content' => $message->message,
            ])
            ->values()
            ->all();
    }

    private function resolveAudience(Request $request): string
    {
        $audience = (string) $request->input('audience', '');

        if (in_array($audience, ['resident', 'staff'], true)) {
            return $audience;
        }

        if ($request->input('source') === 'admin') {
            return 'staff';
        }

        $context = $request->input('context', []);

        if (is_array($context) && (($context['app_section'] ?? null) === 'rhu_admin_dashboard')) {
            return 'staff';
        }

        return 'resident';
    }

    private function safeContext(array $context): array
    {
        return collect($context)
            ->only([
                'current_page',
                'current_button',
                'role',
                'barangay',
                'barangay_id',
                'language',
                'app_section',
                'source',
            ])
            ->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value) => trim((string) $value))
            ->all();
    }

    private function formatMessage(ChatMessage $message): array
    {
        return [
            'id' => (string) $message->id,
            'role' => $message->role,
            'content' => $message->message,
            'timestamp' => optional($message->created_at)->toIso8601String() ?? now()->toIso8601String(),
        ];
    }

    private function formatSession(ChatSession $session): array
    {
        $lastMessage = ChatMessage::query()
            ->where('chat_session_id', $session->id)
            ->latest('created_at')
            ->first();

        $preview = $lastMessage?->message ?? $session->title ?? 'New chat';

        return [
            'id' => $session->session_token ?: (string) $session->id,
            'title' => $session->title ?: Str::limit($preview, 44, '...'),
            'audience' => $session->audience ?: 'resident',
            'status' => $session->status ?: 'active',
            'started_at' => optional($session->created_at)->toIso8601String() ?? now()->toIso8601String(),
            'updated_at' => optional($session->updated_at)->toIso8601String(),
            'last_activity_at' => optional($session->last_activity_at)->toIso8601String(),
            'preview' => Str::limit($preview, 120, '...'),
            'message_count' => (int) ($session->messages_count ?? ChatMessage::where('chat_session_id', $session->id)->count()),
        ];
    }

    private function makeSessionTitle(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message)) ?: 'New chat';

        return Str::limit($message, 60, '...');
    }

    private function detectLanguage(string $message): string
    {
        $lower = mb_strtolower($message);

        if ($this->containsAny($lower, ['paano', 'saan', 'gamot', 'lagnat', 'kumusta', 'maglagay', 'pinamigay'])) {
            return 'tl';
        }

        return 'en';
    }

    private function detectIntent(string $message, string $audience): string
    {
        $lower = mb_strtolower($message);

        if ($this->containsAny($lower, ['emergency', 'chest pain', 'hirap huminga', 'severe bleeding'])) {
            return 'emergency_guidance';
        }

        if ($audience === 'staff') {
            return match (true) {
                $this->containsAny($lower, ['report', 'reports', 'ulat', 'pinamigay', 'dispensed', 'export', 'csv']) => 'reports_guidance',
                $this->containsAny($lower, ['queue', 'pila', 'call next', 'serving']) => 'queue_guidance',
                $this->containsAny($lower, ['appointment', 'booking', 'schedule']) => 'appointment_guidance',
                $this->containsAny($lower, ['consultation', 'soap', 'diagnosis', 'notes']) => 'consultation_guidance',
                $this->containsAny($lower, ['telemedicine', 'video', 'online consult']) => 'telemedicine_guidance',
                $this->containsAny($lower, ['prescription', 'reseta', 'e-prescription']) => 'prescription_guidance',
                $this->containsAny($lower, ['inventory', 'stock', 'vaccine', 'medicine', 'gamot']) => 'inventory_guidance',
                $this->containsAny($lower, ['analytics', 'dashboard', 'heatmap', 'trend']) => 'analytics_guidance',
                $this->containsAny($lower, ['announcement', 'event', 'cms', 'post', 'program']) => 'cms_guidance',
                $this->containsAny($lower, ['sms', 'text', 'semaphore', 'notification']) => 'sms_guidance',
                $this->containsAny($lower, ['user', 'approve', 'verify', 'account']) => 'user_management_guidance',
                default => 'staff_workflow_guidance',
            };
        }

        return match (true) {
            $this->containsAny($lower, ['book', 'appointment', 'schedule', 'konsultasyon']) => 'appointment',
            $this->containsAny($lower, ['record', 'records', 'rekord', 'history']) => 'records',
            $this->containsAny($lower, ['event', 'program', 'announcement']) => 'events_programs',
            $this->containsAny($lower, ['telemedicine', 'video', 'online']) => 'telemedicine',
            default => 'general_health_or_app_guidance',
        };
    }

    private function suggestAction(string $message, string $audience, string $intent): ?string
    {
        if ($audience === 'resident') {
            return match ($intent) {
                'appointment' => 'book_appointment',
                'records' => 'view_records',
                default => null,
            };
        }

        return match ($intent) {
            'reports_guidance' => 'open_reports',
            'queue_guidance' => 'open_queue',
            'appointment_guidance' => 'open_appointments',
            'consultation_guidance' => 'open_consultations',
            'telemedicine_guidance' => 'open_telemedicine',
            'prescription_guidance' => 'open_prescriptions',
            'inventory_guidance' => 'open_inventory',
            'analytics_guidance' => str_contains(mb_strtolower($message), 'heatmap') ? 'open_heatmap' : 'open_analytics',
            'cms_guidance' => 'open_cms',
            'sms_guidance' => 'open_sms',
            'user_management_guidance' => 'open_users',
            default => null,
        };
    }

    private function tutorialCards(?string $suggestedAction, string $intent): array
    {
        return match ($suggestedAction) {
            'open_reports' => [
                [
                    'title' => '1. Click Reports button',
                    'body' => 'Use Reports when the staff needs printable or exportable summaries, including dispensed medicines.',
                ],
                [
                    'title' => '2. Select report type',
                    'body' => 'Choose the medicine dispensing, prescription, consultation, queue, or inventory report depending on the needed output.',
                ],
                [
                    'title' => '3. Filter and export',
                    'body' => 'Set date range, RHU, barangay, or medicine filters, then preview before exporting or printing.',
                ],
            ],
            'open_queue' => [
                [
                    'title' => '1. Click Queue button',
                    'body' => 'Review waiting tickets and priority flags before calling the next patient.',
                ],
                [
                    'title' => '2. Serve in order',
                    'body' => 'Use Call Next, Serving, and Done to keep the flow fair and traceable.',
                ],
                [
                    'title' => '3. Check priority reasons',
                    'body' => 'Senior, PWD, pregnant, pediatric, emergency, and BHW-assisted flags explain priority.',
                ],
            ],
            'open_sms' => [
                [
                    'title' => '1. Click SMS button',
                    'body' => 'Create a short announcement, reminder, or follow-up message.',
                ],
                [
                    'title' => '2. Choose recipients',
                    'body' => 'Filter by barangay, account status, program, age group, sex, or RHU targeting.',
                ],
                [
                    'title' => '3. Preview first',
                    'body' => 'Check recipient count and message privacy before sending.',
                ],
            ],
            'open_users' => [
                [
                    'title' => '1. Click Users button',
                    'body' => 'Open pending, active, or rejected accounts.',
                ],
                [
                    'title' => '2. Review verification',
                    'body' => 'Compare profile details and uploaded ID/OCR result before approval.',
                ],
                [
                    'title' => '3. Save decision',
                    'body' => 'Approve, reject, or request correction based on RHU account validation rules.',
                ],
            ],
            default => $intent === 'staff_workflow_guidance' ? [
                [
                    'title' => 'Tip',
                    'body' => 'Ask for the exact button name or task, for example: “How do I export reports?”',
                ],
            ] : [],
        };
    }

    private function normalizeStaffButtonLanguage(string $reply): string
    {
        $replacements = [
            'Queue module' => 'Queue button',
            'Appointments module' => 'Appointments button',
            'Appointment module' => 'Appointments button',
            'Consultations module' => 'Consultations button',
            'Telemedicine module' => 'Telemedicine button',
            'Prescriptions module' => 'Prescriptions button',
            'Inventory module' => 'Inventory button',
            'Analytics module' => 'Analytics button',
            'Heatmap module' => 'Heatmap button',
            'CMS module' => 'CMS button',
            'SMS module' => 'SMS button',
            'Reports module' => 'Reports button',
            'Users module' => 'Users button',
            'Settings module' => 'Settings button',
            'Dashboard module' => 'Dashboard button',
            'module' => 'button',
            'Module' => 'Button',
            'page' => 'button',
            'Page' => 'Button',
        ];

        return strtr($reply, $replacements);
    }

    private function detectComplaint(string $message): ?string
    {
        $lower = mb_strtolower($message);

        $map = [
            'fever' => ['fever', 'lagnat'],
            'cough' => ['cough', 'ubo'],
            'headache' => ['headache', 'sakit ng ulo'],
            'abdominal pain' => ['stomach pain', 'sakit ng tiyan', 'abdominal pain'],
            'diarrhea' => ['diarrhea', 'pagtatae'],
            'wound' => ['wound', 'sugat'],
            'breathing difficulty' => ['hirap huminga', 'difficulty breathing'],
        ];

        foreach ($map as $label => $keywords) {
            if ($this->containsAny($lower, $keywords)) {
                return $label;
            }
        }

        return null;
    }

    private function mirrorToChatLogs(?int $userId, ChatSession $session, string $role, string $message, string $intent, string $language, ?int $responseMs): void
    {
        if (!Schema::hasTable('chat_logs')) {
            return;
        }

        try {
            ChatLog::create([
                'user_id' => $userId,
                'session_token' => $session->session_token,
                'role' => $role,
                'message' => $message,
                'intent' => $intent,
                'language' => $language,
                'response_ms' => $responseMs,
                'was_escalated' => false,
            ]);
        } catch (\Throwable) {
            // Chat logs are secondary. Do not break the user-facing chat if legacy log columns differ.
        }
    }

    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }
}