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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
