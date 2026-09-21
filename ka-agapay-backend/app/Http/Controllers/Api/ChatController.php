<?php
// app/Http/Controllers/Api/ChatController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatLog;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\Ai\CmsDraftParser;
use App\Services\Ai\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly CmsDraftParser $cmsDraftParser,
    ) {}

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

        // "look for Clifford" asks the assistant to DO something, not to
        // explain how. Those are answered by opening the right page with the
        // search already filled in, instead of listing steps the staff member
        // then has to follow by hand.
        $searchRequest = $audience === 'staff' ? $this->searchRequest($message) : null;

        // "show pending appointments", "today's queue": open the page with the
        // filter already applied. Filtering changes no records either.
        $filterRequest = $audience === 'staff' && $searchRequest === null
            ? $this->filterRequest($message)
            : null;

        // "How many patients are waiting?" — the assistant cannot read records,
        // and guessing or lecturing about where to click is worse than saying
        // so and opening the screen that shows the number.
        $countQuestion = $audience === 'staff' && $searchRequest === null && $filterRequest === null
            ? $this->countQuestion($message)
            : null;

        if ($searchRequest !== null) {
            $suggestedAction = $searchRequest['action'];
        } elseif ($filterRequest !== null) {
            $suggestedAction = $filterRequest['action'];
        } elseif ($countQuestion !== null) {
            $suggestedAction = $countQuestion['action'];
        }

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

        $uiLanguage = (string) ($context['ui_language'] ?? '');

        $reply = match (true) {
            $searchRequest !== null => $this->searchReply($searchRequest, $uiLanguage),
            $filterRequest !== null => $this->filterReply($filterRequest, $uiLanguage),
            $countQuestion !== null => $this->countReply($countQuestion, $uiLanguage),
            // The staff member goes with the question: the assistant may then
            // read counts from their own RHU's records rather than guess.
            default => $this->geminiService->chat($message, $history, $audience, $context, $user),
        };

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
            // Lets the dashboard not just open the page but arrive with the
            // search already filled in, e.g. "find patient Clifford".
            'action_params' => match (true) {
                $searchRequest !== null => ['search' => $searchRequest['term']],
                $filterRequest !== null => $filterRequest['params'],
                $countQuestion !== null => $countQuestion['params'],
                default => $this->actionParams($message, $suggestedAction),
            },
            'tutorial_cards' => $audience === 'staff'
                ? $this->tutorialCards($suggestedAction, $intent)
                : [],

            // When the assistant drafted CMS content, hand the web admin a
            // structured version of the SAME text it just rendered, so staff can
            // load it straight into the Event form instead of copying 15 fields
            // by hand. Null for anything that is not a draft.
            'cms_draft' => $audience === 'staff'
                ? $this->cmsDraftParser->parse($reply)
                : null,
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
    /**
     * POST /chat/stream
     *
     * The same answer as /chat/message, sent a piece at a time so the first
     * words appear in about a second instead of the whole paragraph arriving
     * after four. Server-sent events: "chunk" carries text, "done" carries the
     * session, the page to open and any search or filter for it.
     *
     * Deterministic answers (a search, a filter, a count question) arrive in
     * one chunk — they were never slow. Only the model's own prose streams.
     *
     * This holds a PHP worker open for the length of the answer, which is fine
     * for a few dozen staff and would not be for thousands.
     */
    public function stream(Request $request): StreamedResponse
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

        $searchRequest = $audience === 'staff' ? $this->searchRequest($message) : null;
        $filterRequest = $audience === 'staff' && $searchRequest === null
            ? $this->filterRequest($message)
            : null;
        $countQuestion = $audience === 'staff' && $searchRequest === null && $filterRequest === null
            ? $this->countQuestion($message)
            : null;

        if ($searchRequest !== null) {
            $suggestedAction = $searchRequest['action'];
        } elseif ($filterRequest !== null) {
            $suggestedAction = $filterRequest['action'];
        } elseif ($countQuestion !== null) {
            $suggestedAction = $countQuestion['action'];
        }

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
        $uiLanguage = (string) ($context['ui_language'] ?? '');

        $actionParams = match (true) {
            $searchRequest !== null => ['search' => $searchRequest['term']],
            $filterRequest !== null => $filterRequest['params'],
            $countQuestion !== null => $countQuestion['params'],
            default => $this->actionParams($message, $suggestedAction),
        };

        $deterministic = match (true) {
            $searchRequest !== null => $this->searchReply($searchRequest, $uiLanguage),
            $filterRequest !== null => $this->filterReply($filterRequest, $uiLanguage),
            $countQuestion !== null => $this->countReply($countQuestion, $uiLanguage),
            default => null,
        };

        return response()->stream(function () use (
            $deterministic, $message, $history, $audience, $context, $user,
            $session, $language, $intent, $suggestedAction, $actionParams, $start
        ) {
            $send = function (string $event, array $data): void {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data) . "\n\n";

                // Push it to the browser now rather than at the end, which is
                // the entire point of streaming.
                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();
            };

            $reply = '';

            try {
                if ($deterministic !== null) {
                    $reply = $deterministic;
                    $send('chunk', ['text' => $reply]);
                } elseif ($audience === 'staff' && $this->countQuestion($message) === null && $this->needsLiveData($message)) {
                    // Questions about numbers go the ordinary route, where the
                    // assistant may read this RHU's records before answering.
                    $reply = $this->geminiService->chat($message, $history, $audience, $context, $user);
                    $send('chunk', ['text' => $reply]);
                } else {
                    $reply = $this->geminiService->streamChat(
                        $message,
                        $history,
                        $audience,
                        $context,
                        function (string $piece) use ($send, &$reply) {
                            $send('chunk', ['text' => $piece]);
                        }
                    );
                }

                if ($audience === 'staff') {
                    $reply = $this->normalizeStaffButtonLanguage($reply);
                }
            } catch (\Throwable $e) {
                Log::warning('[ChatController] Stream failed', ['error' => $e->getMessage()]);

                $reply = $reply !== '' ? $reply : 'Sorry, I could not finish that answer. Please try again.';
                $send('chunk', ['text' => $reply === '' ? '' : '']);
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

            $send('done', [
                'message' => $this->formatMessage($assistantMessage),
                'session_id' => $session->session_token,
                'suggested_action' => $suggestedAction,
                'action_params' => $actionParams,
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no', // nginx must not hold the pieces back
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Whether the question is about live figures, which the streaming path
     * cannot look up (it runs without tools) and the ordinary path can.
     */
    private function needsLiveData(string $message): bool
    {
        $lower = mb_strtolower($message);

        return $this->containsAny($lower, [
            'how many', 'how much', 'ilan', 'pigara', 'total', 'count',
            'low stock', 'out of stock', 'waiting', 'overdue', 'pending',
        ]);
    }

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
                // 'tutorial' switches the staff assistant to the Getting
                // Started onboarding persona; any other value is operations.
                'assistant_mode',
                // The language the dashboard is set to (en / tag / pag). The
                // reply comes back in this language, whatever the question was
                // typed in.
                'ui_language',
                // Set when the user turned on simple mode: short, spoken-style
                // answers for staff who are not comfortable with computers.
                'simple_mode',
                /*
                 * The figures drawn on the screen the question was asked
                 * from, as a few labelled lines. Staff ask "what does this
                 * mean?" while looking at a chart, and an assistant that
                 * cannot see the chart can only answer in generalities.
                 *
                 * Aggregates only -- totals, percentages, the period in
                 * view. The dashboard decides what a page may publish and
                 * sends no patient, no record and no image of the screen.
                 */
                'screen',
            ])
            ->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value, $key) => $key === 'screen'
                // The screen block is several labelled lines and has to keep
                // its shape; everything else here is a single short value.
                ? mb_substr(trim((string) $value), 0, 2000)
                : trim((string) $value))
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
                $this->containsAny($lower, ['patient registry', 'patient profile', 'patients list', 'active patients']) => 'patient_registry_guidance',
                $this->containsAny($lower, ['team chat', 'co-worker', 'coworker', 'staff chat', 'group chat']) => 'team_chat_guidance',
                $this->containsAny($lower, ['registration approval', 'registration approvals', 'registrations', 'view ocr', 'employee id']) => 'registration_approval_guidance',
                $this->containsAny($lower, ['feedback', 'service feedback', 'condition update', 'resident update']) => 'feedback_guidance',
                $this->containsAny($lower, ['follow-up', 'follow up', 'followups', 'reminder']) => 'followup_guidance',
                $this->containsAny($lower, ['notification', 'notifications', 'alert', 'alerts', 'bell']) => 'notification_guidance',
                $this->containsAny($lower, ['setting', 'settings', 'backup', 'sms provider', 'system control']) => 'settings_guidance',
                $this->containsAny($lower, ['report', 'reports', 'ulat', 'pinamigay', 'dispensed', 'export', 'csv']) => 'reports_guidance',
                $this->containsAny($lower, ['queue', 'pila', 'call next', 'serving']) => 'queue_guidance',
                $this->containsAny($lower, ['appointment', 'booking', 'schedule']) => 'appointment_guidance',
                $this->containsAny($lower, ['consultation', 'soap', 'diagnosis', 'notes']) => 'consultation_guidance',
                $this->containsAny($lower, ['telemedicine', 'video', 'online consult']) => 'telemedicine_guidance',
                $this->containsAny($lower, ['prescription', 'reseta', 'e-prescription']) => 'prescription_guidance',
                $this->containsAny($lower, ['inventory', 'stock', 'vaccine', 'medicine', 'gamot']) => 'inventory_guidance',
                $this->containsAny($lower, ['heatmap', 'disease cluster', 'barangay risk']) => 'heatmap_guidance',
                $this->containsAny($lower, ['analytics', 'dashboard', 'trend']) => 'analytics_guidance',
                $this->containsAny($lower, ['event', 'events', 'program']) => 'events_guidance',
                $this->containsAny($lower, ['announcement', 'cms', 'post']) => 'cms_guidance',
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
            'patient_registry_guidance' => 'open_patient_registry',
            'reports_guidance' => 'open_reports',
            'queue_guidance' => 'open_queue',
            'appointment_guidance' => 'open_appointments',
            'consultation_guidance' => 'open_consultations',
            'telemedicine_guidance' => 'open_telemedicine',
            'prescription_guidance' => 'open_prescriptions',
            'team_chat_guidance' => 'open_team_chat',
            'inventory_guidance' => 'open_inventory',
            'analytics_guidance' => 'open_analytics',
            'heatmap_guidance' => 'open_heatmap',
            'cms_guidance' => 'open_cms',
            'events_guidance' => 'open_events',
            'feedback_guidance' => 'open_feedback',
            'followup_guidance' => 'open_followups',
            'notification_guidance' => 'open_notifications',
            'sms_guidance' => 'open_sms',
            'registration_approval_guidance' => 'open_registrations',
            'user_management_guidance' => 'open_users',
            'settings_guidance' => 'open_settings',
            default => null,
        };
    }

    /**
     * Which page's search box to fill, keyed by the words staff use for it.
     * Order matters: the first match wins.
     */
    private const SEARCH_TARGETS = [
        'open_prescriptions' => ['prescription', 'reseta', 'lab request', 'laboratory'],
        'open_users' => ['user account', 'staff account', 'user', 'staff'],
        'open_followups' => ['follow-up', 'follow up', 'followup', 'bantay'],
        'open_inventory' => ['inventory', 'medicine', 'gamot', 'stock', 'supply'],
        'open_events' => ['event', 'program', 'announcement', 'activity'],
        'open_registrations' => ['registration', 'approval', 'applicant'],
        'open_patient_registry' => ['patient', 'pasyente', 'resident', 'registry'],
    ];

    /**
     * A request to look someone or something up, rather than a question about
     * how the system works. Returns the page to open and the term to search.
     *
     * @return array{action: string, term: string}|null
     */
    private function searchRequest(string $message): ?array
    {
        // "How do I search for a patient?" wants instructions, not a search.
        if (preg_match('/^\s*(how|what|where|why|when|paano|ano|iner|akin|panon)\b/iu', $message)) {
            return null;
        }

        $term = $this->extractSearchTerm($message);

        if ($term === null) {
            return null;
        }

        // Strip a leading article left by phrases like "find a patient Maria".
        $term = trim((string) preg_replace('/^(a|an|the|ang|si|so|say)\s+/iu', '', $term));

        if ($term === '' || mb_strlen($term) < 2) {
            return null;
        }

        $lower = mb_strtolower($message);

        foreach (self::SEARCH_TARGETS as $action => $keywords) {
            if ($this->containsAny($lower, $keywords)) {
                return ['action' => $action, 'term' => $term];
            }
        }

        // A bare name: people are what staff look for most often.
        return ['action' => 'open_patient_registry', 'term' => $term];
    }

    /** Screen names as staff see them in the sidebar. */
    private const PAGE_LABELS = [
        'open_patient_registry' => 'Patient Registry',
        'open_prescriptions' => 'E-Prescription / Lab Requests',
        'open_users' => 'Users',
        'open_followups' => 'Health Follow-up',
        'open_inventory' => 'Inventory',
        'open_events' => 'Events',
        'open_registrations' => 'Registration Approvals',
        'open_appointments' => 'Appointments',
        'open_queue' => 'Queue',
        'open_consultations' => 'Consultations',
    ];

    /** Which page each list word belongs to. */
    private const FILTER_TARGETS = [
        'open_appointments' => ['appointment', 'tipanan', 'booking', 'schedule'],
        'open_queue' => ['queue', 'pila', 'ticket'],
        'open_consultations' => ['consultation', 'konsulta', 'check-up', 'checkup'],
        'open_prescriptions' => ['prescription', 'reseta'],
        'open_followups' => ['follow-up', 'follow up', 'followup'],
        'open_registrations' => ['registration', 'approval', 'applicant'],
    ];

    /** List filters the pages understand, and the words staff use for them. */
    private const FILTER_WORDS = [
        'pending' => ['pending', 'naghihintay', 'nakabinbin', 'akaalagar'],
        'approved' => ['approved', 'aprubado'],
        'completed' => ['completed', 'finished', 'tapos', 'asumpal'],
        'cancelled' => ['cancelled', 'canceled', 'kanselado'],
        'overdue' => ['overdue', 'late', 'lampas'],
        'missed' => ['missed', 'no show', 'no-show'],
        'upcoming' => ['upcoming', 'paparating', 'onsabi'],
        'dispensed' => ['dispensed', 'naibigay'],
        'active' => ['active', 'aktibo', 'ongoing'],
        'waiting' => ['waiting', 'naghihintay na pasyente'],
    ];

    /**
     * A request to see a filtered list rather than a question about the system.
     *
     * @return array{action: string, params: array<string, string>}|null
     */
    private function filterRequest(string $message): ?array
    {
        if (preg_match('/^\s*(how|what|where|why|when|paano|ano|iner|akin|panon)\b/iu', $message)) {
            return null;
        }

        $lower = mb_strtolower($message);
        $action = null;

        foreach (self::FILTER_TARGETS as $candidate => $keywords) {
            if ($this->containsAny($lower, $keywords)) {
                $action = $candidate;
                break;
            }
        }

        if ($action === null) {
            return null;
        }

        $params = [];

        foreach (self::FILTER_WORDS as $status => $words) {
            if ($this->containsAny($lower, $words)) {
                $params['status'] = $status;
                break;
            }
        }

        if ($this->containsAny($lower, ['today', 'ngayon', 'natan', 'this morning'])) {
            // The follow-up board has "today" as one of its tabs; the
            // appointment board keeps the day separate from the status.
            $params[$action === 'open_followups' ? 'status' : 'date'] = 'today';
        }

        return $params === [] ? null : ['action' => $action, 'params' => $params];
    }

    /**
     * A question about numbers the assistant cannot see ("how many patients
     * are waiting?", "ilan ang pending appointments?").
     *
     * The honest answer is that it has no access to records, followed by the
     * screen where the figure is, with the filter already applied where the
     * question implies one.
     *
     * @return array{action: string, params: array<string, string>}|null
     */
    private function countQuestion(string $message): ?array
    {
        $lower = mb_strtolower($message);

        if (!$this->containsAny($lower, ['how many', 'how much', 'ilan', 'pigara', 'total ng', 'count of'])) {
            return null;
        }

        foreach (self::FILTER_TARGETS as $action => $keywords) {
            if ($this->containsAny($lower, $keywords)) {
                $params = [];

                foreach (self::FILTER_WORDS as $status => $words) {
                    if ($this->containsAny($lower, $words)) {
                        $params['status'] = $status;
                        break;
                    }
                }

                if ($this->containsAny($lower, ['today', 'ngayon', 'natan'])) {
                    $params[$action === 'open_followups' ? 'status' : 'date'] = 'today';
                }

                return ['action' => $action, 'params' => $params];
            }
        }

        if ($this->containsAny($lower, ['patient', 'pasyente', 'resident'])) {
            return ['action' => 'open_patient_registry', 'params' => []];
        }

        return null;
    }

    /** Admitting the limit, then opening the screen that holds the answer. */
    private function countReply(array $question, string $language): string
    {
        $page = self::PAGE_LABELS[$question['action']] ?? 'the page';

        return match (strtolower(trim($language))) {
            'tag', 'tl', 'fil', 'tagalog', 'filipino' =>
                "Hindi ko po mabasa ang mga record, kaya hindi ko masasabi ang bilang. Bubuksan ko po ang {$page} — nasa itaas ng listahan ang kabuuan.",
            'pag', 'pangasinan', 'pangasinense' =>
                "Agko nabasa so saray record, kanian agko nibaga so bilang. Lukasan ko so {$page} — walad tagey na listaan so kabuoan.",
            default =>
                "I cannot read the records, so I cannot give you the number. Opening {$page} — the total is shown above the list.",
        };
    }

    /** What the assistant says while it opens a filtered list. */
    private function filterReply(array $filter, string $language): string
    {
        $page = self::PAGE_LABELS[$filter['action']] ?? 'the page';
        $status = $filter['params']['status'] ?? null;
        $today = ($filter['params']['date'] ?? '') === 'today';

        $what = match (true) {
            $status !== null && $today => "{$status}, today",
            $status !== null => $status,
            default => 'today',
        };

        return match (strtolower(trim($language))) {
            'tag', 'tl', 'fil', 'tagalog', 'filipino' =>
                "Bubuksan ko po ang {$page} at ipapakita ang \"{$what}\". Pwede pong baguhin ang filter sa itaas ng listahan.",
            'pag', 'pangasinan', 'pangasinense' =>
                "Lukasan ko so {$page} tan ipanengneng so \"{$what}\". Nayarian mon umanen so filter ed tagey na listaan.",
            default =>
                "Opening {$page}, showing {$what}. You can change the filter at the top of the list.",
        };
    }

    /**
     * What the assistant says while it opens the page. Short and specific, in
     * the language the staff member chose, and it names what to do when the
     * search finds nothing.
     */
    private function searchReply(array $search, string $language): string
    {
        $page = self::PAGE_LABELS[$search['action']] ?? 'the page';
        $term = $search['term'];

        return match (strtolower(trim($language))) {
            'tag', 'tl', 'fil', 'tagalog', 'filipino' =>
                "Bubuksan ko po ang {$page} at hahanapin ang \"{$term}\". Kung walang lumabas, pakisuri po ang baybay o subukan ang apelyido.",
            'pag', 'pangasinan', 'pangasinense' =>
                "Lukasan ko so {$page} tan anapen ko so \"{$term}\". No anggapoy ompaway, nengnengen so espeling odino usar so apelyido.",
            default =>
                "Opening {$page} and searching for \"{$term}\". If nothing appears, check the spelling or try the surname.",
        };
    }

    /**
     * Extra instructions for the page the assistant is about to open. Only
     * searching is supported: it changes nothing, so a misheard name is
     * harmless — the staff member simply sees no results and retypes.
     *
     * @return array<string, string>
     */
    private function actionParams(string $message, ?string $suggestedAction): array
    {
        $searchable = [
            'open_patient_registry',
            'open_prescriptions',
            'open_users',
            'open_followups',
            'open_registrations',
            'open_inventory',
            'open_events',
        ];

        if (!$suggestedAction || !in_array($suggestedAction, $searchable, true)) {
            return [];
        }

        $term = $this->extractSearchTerm($message);

        return $term === null ? [] : ['search' => $term];
    }

    /**
     * The thing the user asked to look for, in English, Tagalog or Pangasinan
     * ("find patient Maria", "hanapin si Maria", "anapen si Maria"). Returns
     * null when the message is a general question rather than a search.
     */
    private function extractSearchTerm(string $message): ?string
    {
        $pattern = '/\b(?:search|find|look\s+up|look\s+for|show\s+me|hanap|hanapin|hanapen|anapen|nengnengen|ipakita)\b'
            . '\s*(?:for|si|sina|ang|so|say|the)?\s*'
            . '(?:patient|pasyente|resident|user|staff|record|reseta|prescription|item|medicine|gamot|event)?\s*'
            . '(?:named|na|ya)?\s*[:\-]?\s*(.+)$/iu';

        if (!preg_match($pattern, $message, $matches)) {
            return null;
        }

        // Drop trailing politeness and punctuation: "hanapin si Maria po, salamat".
        $term = preg_replace('/\b(po|please|salamat|thanks|thank you)\b/iu', ' ', $matches[1]);
        $term = trim((string) preg_replace('/[\p{P}\p{S}]+$/u', '', trim((string) $term)));

        if ($term === '' || mb_strlen($term) < 2 || mb_strlen($term) > 60) {
            return null;
        }

        return $term;
    }

    private function tutorialCards(?string $suggestedAction, string $intent): array
    {
        return match ($suggestedAction) {
            'open_dashboard' => [
                [
                    'title' => '1. Click Dashboard button',
                    'body' => 'Real-Time RHU Dashboard: live tracking for patients, consultations, queue, telemedicine, inventory, and barangay health heatmap.',
                    'mascot' => '/Wavingduck.png',
                ],
                [
                    'title' => '2. Start with priorities',
                    'body' => 'Check Priority Action Center, Shift Summary, and KPI cards before opening source records.',
                    'mascot' => '/Wavingduck.png',
                ],
            ],
            'open_patient_registry' => [
                [
                    'title' => '1. Click Patient Registry button',
                    'body' => 'Browse and search active patients, then open a profile for full history.',
                    'mascot' => '/HappyDuckloving.png',
                ],
                [
                    'title' => '2. Open the patient profile',
                    'body' => 'Search by name or mobile number and use View to confirm the history before follow-up work.',
                    'mascot' => '/HappyDuckloving.png',
                ],
            ],
            'open_appointments' => [
                [
                    'title' => '1. Click Appointments button',
                    'body' => 'Simple RHU appointment board for approving, scheduling, rejecting, adding onsite patients to queue, and starting consultations.',
                    'mascot' => '/Thinkingduck.png',
                ],
                [
                    'title' => '2. Decide the next action',
                    'body' => 'Confirm onsite versus online type, then approve, schedule, add to queue, or open telemedicine.',
                    'mascot' => '/Thinkingduck.png',
                ],
            ],
            'open_consultations' => [
                [
                    'title' => '1. Click Consultations button',
                    'body' => 'Review active consultation records, open SOAP documentation, check diagnosis status, and complete required records.',
                    'mascot' => '/Consultationduck.png',
                ],
                [
                    'title' => '2. Complete documentation',
                    'body' => 'Add SOAP, diagnosis, prescription, lab request, referral, or follow-up details before completion.',
                    'mascot' => '/Consultationduck.png',
                ],
            ],
            'open_telemedicine' => [
                [
                    'title' => '1. Click Telemedicine button',
                    'body' => 'Screen online consultation requests, open video sessions, track request progress, and safely complete SOAP documentation.',
                    'mascot' => '/Duckcheckingmobilephone.png',
                ],
                [
                    'title' => '2. Finish the record',
                    'body' => 'After the call, save or finalize SOAP notes from the telemedicine room or linked consultation.',
                    'mascot' => '/Duckcheckingmobilephone.png',
                ],
            ],
            'open_prescriptions' => [
                [
                    'title' => '1. Click E-Prescription button',
                    'body' => 'Create medicine prescriptions or laboratory requests and release official PDFs.',
                    'mascot' => '/Consultationduck.png',
                ],
                [
                    'title' => '2. Review before release',
                    'body' => 'Check the patient context and request details before releasing the PDF or dispensing.',
                    'mascot' => '/Consultationduck.png',
                ],
            ],
            'open_team_chat' => [
                [
                    'title' => '1. Click Team Chat button',
                    'body' => 'Internal staff messaging with chats, group conversations, search, presence, seen receipts, and voice/video calls.',
                    'mascot' => '/Side-waved duck.png',
                ],
                [
                    'title' => '2. Coordinate with staff',
                    'body' => 'Use Search, New chat, or New group while respecting RHU visibility rules.',
                    'mascot' => '/Side-waved duck.png',
                ],
            ],
            'open_inventory' => [
                [
                    'title' => '1. Click Inventory button',
                    'body' => 'Real-time monitoring of medicines, vaccines, supplies, and equipment, including restock needs and expiry safety.',
                    'mascot' => '/Thinkingduck.png',
                ],
                [
                    'title' => '2. Follow FEFO',
                    'body' => 'Record stock-in, stock-out, or adjustment history; expired items should not be dispensed.',
                    'mascot' => '/Thinkingduck.png',
                ],
            ],
            'open_cms' => [
                [
                    'title' => '1. Click Announcements button',
                    'body' => 'Content Management: create simple, readable, and timely public information for Ka-Agapay residents.',
                    'mascot' => '/Side-waved duck.png',
                ],
                [
                    'title' => '2. Preview before publishing',
                    'body' => 'Write a clear title, preview resident view, publish only when final, and archive old advisories.',
                    'mascot' => '/Side-waved duck.png',
                ],
            ],
            'open_events' => [
                [
                    'title' => '1. Click Events button',
                    'body' => 'Events & Programs Management: create clear RHU events, health programs, and public advisories.',
                    'mascot' => '/Side-waved duck.png',
                ],
                [
                    'title' => '2. Complete required fields',
                    'body' => 'Review schedule, location, audience, barangay target, RHU service, visibility, and SMS summary before publishing.',
                    'mascot' => '/Side-waved duck.png',
                ],
            ],
            'open_reports' => [
                [
                    'title' => '1. Click Reports button',
                    'body' => 'Use Reports when the staff needs printable or exportable summaries, including dispensed medicines.',
                    'mascot' => '/Lightbulbduck.png',
                ],
                [
                    'title' => '2. Select report type',
                    'body' => 'Choose the medicine dispensing, prescription, consultation, queue, or inventory report depending on the needed output.',
                    'mascot' => '/Lightbulbduck.png',
                ],
                [
                    'title' => '3. Filter and export',
                    'body' => 'Set date range, RHU, barangay, or medicine filters, then preview before exporting or printing.',
                    'mascot' => '/Thumbsupduck.png',
                ],
            ],
            'open_analytics' => [
                [
                    'title' => '1. Click Analytics button',
                    'body' => 'Track patients, consultations, telemedicine usage, queue tickets, disease clusters, and chatbot questions for better RHU planning.',
                    'mascot' => '/Lightbulbduck.png',
                ],
                [
                    'title' => '2. Validate insights',
                    'body' => 'Use analytics as a guide and validate high-risk records before making decisions or public advisories.',
                    'mascot' => '/Lightbulbduck.png',
                ],
            ],
            'open_heatmap' => [
                [
                    'title' => '1. Click Heatmap Analytics button',
                    'body' => 'Use separate operational workspaces for RHU queue monitoring and barangay disease cluster surveillance.',
                    'mascot' => '/Lightbulbduck.png',
                ],
                [
                    'title' => '2. Review active signals',
                    'body' => 'Check queue density or barangay disease clusters, then validate before SMS or CMS action.',
                    'mascot' => '/Lightbulbduck.png',
                ],
            ],
            'open_queue' => [
                [
                    'title' => '1. Click Queue button',
                    'body' => 'Review waiting tickets and priority flags before calling the next patient.',
                    'mascot' => '/Thinkingduck.png',
                ],
                [
                    'title' => '2. Serve in order',
                    'body' => 'Use Call Next, Serving, and Done to keep the flow fair and traceable.',
                    'mascot' => '/Thinkingduck.png',
                ],
                [
                    'title' => '3. Check priority reasons',
                    'body' => 'Senior, PWD, pregnant, pediatric, emergency, and BHW-assisted flags explain priority.',
                    'mascot' => '/Thumbsupduck.png',
                ],
            ],
            'open_feedback' => [
                [
                    'title' => '1. Click Feedback button',
                    'body' => 'Service Feedback: patient service feedback and condition updates submitted from the mobile app.',
                    'mascot' => '/HappyDuckloving.png',
                ],
                [
                    'title' => '2. Route follow-ups',
                    'body' => 'Respond to feedback when appropriate; use Health Follow-up for clinical reminders.',
                    'mascot' => '/HappyDuckloving.png',
                ],
            ],
            'open_followups' => [
                [
                    'title' => '1. Click Health Follow-up button',
                    'body' => 'Track overdue, due today, upcoming, and completed patient follow-ups.',
                    'mascot' => '/HappyDuckloving.png',
                ],
                [
                    'title' => '2. Check the linked record',
                    'body' => 'Open the consultation when clinical context is needed and resend SMS only after review.',
                    'mascot' => '/HappyDuckloving.png',
                ],
            ],
            'open_notifications' => [
                [
                    'title' => '1. Click Notifications button',
                    'body' => 'View mobile requests, queue updates, telemedicine reminders, appointment notices, RHU posts, and system alerts in one inbox.',
                    'mascot' => '/Lightbulbduck.png',
                ],
                [
                    'title' => '2. Open the source',
                    'body' => 'Use the linked page to complete the work, then mark alerts read after review.',
                    'mascot' => '/Lightbulbduck.png',
                ],
            ],
            'open_sms' => [
                [
                    'title' => '1. Click SMS button',
                    'body' => 'Create a short announcement, reminder, or follow-up message.',
                    'mascot' => '/Duckcheckingmobilephone.png',
                ],
                [
                    'title' => '2. Choose recipients',
                    'body' => 'Filter by barangay, account status, program, age group, sex, or RHU targeting.',
                    'mascot' => '/Duckcheckingmobilephone.png',
                ],
                [
                    'title' => '3. Preview first',
                    'body' => 'Check recipient count and message privacy before sending.',
                    'mascot' => '/Thumbsupduck.png',
                ],
            ],
            'open_registrations' => [
                [
                    'title' => '1. Click Registration Approvals button',
                    'body' => 'Review pending registrants - residents and staff. Open View OCR to verify the submitted ID before approval.',
                    'mascot' => '/Thinkingduck.png',
                ],
                [
                    'title' => '2. Approve or reject',
                    'body' => 'Compare profile details and submitted ID/OCR, then approve, reject, or request correction.',
                    'mascot' => '/Thinkingduck.png',
                ],
            ],
            'open_users' => [
                [
                    'title' => '1. Click Users button',
                    'body' => 'Open pending, active, or rejected accounts.',
                    'mascot' => '/Thinkingduck.png',
                ],
                [
                    'title' => '2. Review verification',
                    'body' => 'Compare profile details and uploaded ID/OCR result before approval.',
                    'mascot' => '/Thinkingduck.png',
                ],
                [
                    'title' => '3. Save decision',
                    'body' => 'Approve, reject, or request correction based on RHU account validation rules.',
                    'mascot' => '/Thumbsupduck.png',
                ],
            ],
            'open_settings' => [
                [
                    'title' => '1. Click Settings button',
                    'body' => 'Settings Management: manage RHU information, notifications, security, and backup settings clearly and safely.',
                    'mascot' => '/Thumbsupduck.png',
                ],
                [
                    'title' => '2. Test critical settings',
                    'body' => 'Review details, save valid settings, then test SMS and backup configuration.',
                    'mascot' => '/Thumbsupduck.png',
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
            'Patient Registry module' => 'Patient Registry button',
            'Appointments module' => 'Appointments button',
            'Appointment module' => 'Appointments button',
            'Consultations module' => 'Consultations button',
            'Telemedicine module' => 'Telemedicine button',
            'Prescriptions module' => 'Prescriptions button',
            'E-Prescription module' => 'E-Prescription button',
            'Team Chat module' => 'Team Chat button',
            'Inventory module' => 'Inventory button',
            'Analytics module' => 'Analytics button',
            'Heatmap module' => 'Heatmap Analytics button',
            'Heatmap Analytics module' => 'Heatmap Analytics button',
            'CMS module' => 'CMS button',
            'Announcements module' => 'Announcements button',
            'Events module' => 'Events button',
            'Feedback module' => 'Feedback button',
            'Follow-up module' => 'Health Follow-up button',
            'Notifications module' => 'Notifications button',
            'SMS module' => 'SMS button',
            'Reports module' => 'Reports button',
            'Registration Approvals module' => 'Registration Approvals button',
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
