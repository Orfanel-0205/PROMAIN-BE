<?php
// app/Http/Controllers/Api/ChatController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatLog;
use App\Services\Ai\GeminiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(
        private readonly GeminiService $gemini
    ) {}

    /**
     * POST /api/v1/chat/message
     *
     * Shared by:
     * - Mobile resident app
     * - RHU admin web dashboard
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1500'],
            'history' => ['nullable', 'array'],
            'history.*.role' => ['nullable', 'string'],
            'history.*.content' => ['nullable', 'string'],
            'audience' => ['nullable', 'in:resident,staff'],
            'source' => ['nullable', 'in:mobile,admin,web'],
            'context' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $message = trim($validated['message']);
        $history = $validated['history'] ?? [];
        $source = $validated['source'] ?? 'mobile';
        $context = $validated['context'] ?? [];

        $audience = $validated['audience'] ?? $this->resolveAudience($user, $source);
        $intent = $this->detectIntent($message, $audience);
        $detectedComplaint = $this->detectComplaint($message, $audience);

        ChatLog::create([
            'user_id' => $user?->user_id,
            'role' => 'user',
            'message' => $message,
            'intent' => $intent,
            'language' => $this->detectLanguage($message),
        ]);

        $start = microtime(true);

        try {
            $reply = $this->gemini->chat($message, $history, $audience, [
                ...$context,
                'role' => $this->roleName($user),
                'source' => $source,
            ]);
        } catch (ConnectionException $e) {
            Log::error('[ChatController] Gemini connection failed', [
                'error' => $e->getMessage(),
            ]);

            $reply = $this->safeFallback($audience);
        } catch (\Throwable $e) {
            Log::error('[ChatController] Unexpected chatbot error', [
                'class' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            $reply = $this->safeFallback($audience);
        }

        $duration = (int) ((microtime(true) - $start) * 1000);

        $log = ChatLog::create([
            'user_id' => $user?->user_id,
            'role' => 'assistant',
            'message' => $reply,
            'intent' => $intent,
            'language' => $this->detectLanguage($reply),
            'response_ms' => $duration,
        ]);

        return response()->json([
            'message' => [
                'id' => (string) ($log->id ?? Str::uuid()),
                'role' => 'assistant',
                'content' => $reply,
                'timestamp' => now()->toISOString(),
            ],
            'audience' => $audience,
            'intent' => $intent,
            'detected_complaint' => $detectedComplaint,
            'suggested_action' => $this->suggestAction($message, $reply, $audience),
            'tutorial_cards' => $this->tutorialCards($intent, $audience),
            'meta' => [
                'response_ms' => $duration,
                'source' => $source,
            ],
        ]);
    }

    /**
     * GET /api/v1/chat/history
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $logs = ChatLog::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->user_id))
            ->latest()
            ->limit(80)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($log) => [
                'id' => (string) $log->id,
                'role' => $log->role,
                'content' => $log->message,
                'intent' => $log->intent,
                'timestamp' => $log->created_at?->toISOString() ?? now()->toISOString(),
            ]);

        return response()->json([
            'data' => $logs,
        ]);
    }

    /**
     * POST /api/v1/chat/end
     */
    public function endSession(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Chat session ended.',
        ]);
    }

    /**
     * POST /api/v1/chat/escalate
     */
    public function escalateToDoctor(Request $request): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'message' => 'Escalation request recorded. Please coordinate with the assigned RHU staff or doctor.',
        ]);
    }

    private function resolveAudience($user, string $source): string
    {
        if ($source === 'admin' || $source === 'web') {
            return 'staff';
        }

        $role = $this->roleName($user);

        $staffRoles = [
            'admin',
            'rhu_admin',
            'super_admin',
            'superadmin',
            'staff',
            'staff_admin',
            'mho',
            'doctor',
            'nurse',
            'midwife',
            'bhw',
        ];

        return in_array($role, $staffRoles, true) ? 'staff' : 'resident';
    }

    private function roleName($user): string
    {
        if (!$user) {
            return '';
        }

        $role = $user->relationLoaded('role') ? $user->role : $user->role()->first();

        $value = $role?->name
            ?? $role?->role_name
            ?? $role?->slug
            ?? $role?->code
            ?? '';

        return strtolower(str_replace([' ', '-'], '_', trim((string) $value)));
    }

    private function detectIntent(string $message, string $audience): string
    {
        $text = mb_strtolower($message);

        $map = [
            'emergency' => ['emergency', 'urgent', 'chest pain', 'hirap huminga', 'stroke', 'seizure', 'dumudugo'],
            'appointment' => ['appointment', 'book', 'schedule', 'konsultasyon', 'checkup', 'check up'],
            'records' => ['record', 'records', 'history', 'rekord', 'consultation history'],
            'queue' => ['queue', 'pila', 'ticket', 'call next', 'serving'],
            'telemedicine' => ['telemedicine', 'online consultation', 'video call'],
            'prescription' => ['prescription', 'reseta', 'gamot', 'medicine'],
            'inventory' => ['inventory', 'stock', 'supplies', 'medicine stock'],
            'analytics' => ['analytics', 'report', 'heatmap', 'dashboard', 'chart'],
            'cms' => ['announcement', 'event', 'cms', 'post', 'program'],
            'sms' => ['sms', 'semaphore', 'text blast', 'message residents'],
            'users' => ['users', 'approve', 'approval', 'verify account', 'resident account'],
            'id_verification' => ['ocr', 'id verification', 'upload id', 'valid id'],
            'tutorial' => ['tutorial', 'guide', 'how to', 'paano', 'turo', 'help'],
        ];

        foreach ($map as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $intent;
                }
            }
        }

        return $audience === 'staff' ? 'staff_help' : 'resident_help';
    }

    private function detectComplaint(string $message, string $audience): ?string
    {
        if ($audience === 'staff') {
            return null;
        }

        $text = mb_strtolower($message);

        $keywords = [
            'sakit',
            'masakit',
            'lagnat',
            'ubo',
            'sipon',
            'nahihilo',
            'sugat',
            'dugo',
            'pain',
            'fever',
            'cough',
            'cold',
            'dizzy',
            'wound',
            'bleeding',
            'headache',
            'vomit',
            'diarrhea',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return $message;
            }
        }

        return null;
    }

    private function suggestAction(string $message, string $reply, string $audience): ?string
    {
        $combined = mb_strtolower($message . ' ' . $reply);

        if ($audience === 'staff') {
            return match (true) {
                str_contains($combined, 'queue') || str_contains($combined, 'pila') => 'open_queue',
                str_contains($combined, 'appointment') => 'open_appointments',
                str_contains($combined, 'consultation') => 'open_consultations',
                str_contains($combined, 'telemedicine') => 'open_telemedicine',
                str_contains($combined, 'prescription') || str_contains($combined, 'reseta') => 'open_prescriptions',
                str_contains($combined, 'inventory') || str_contains($combined, 'stock') => 'open_inventory',
                str_contains($combined, 'analytics') || str_contains($combined, 'heatmap') => 'open_analytics',
                str_contains($combined, 'announcement') || str_contains($combined, 'event') || str_contains($combined, 'cms') => 'open_cms',
                str_contains($combined, 'sms') || str_contains($combined, 'semaphore') => 'open_sms',
                str_contains($combined, 'user') || str_contains($combined, 'approval') => 'open_users',
                default => null,
            };
        }

        return match (true) {
            str_contains($combined, 'appointment') || str_contains($combined, 'book') => 'book_appointment',
            str_contains($combined, 'record') || str_contains($combined, 'history') || str_contains($combined, 'rekord') => 'view_records',
            str_contains($combined, 'event') || str_contains($combined, 'program') => 'open_events',
            str_contains($combined, 'id') || str_contains($combined, 'ocr') || str_contains($combined, 'verify') => 'upload_id',
            str_contains($combined, 'emergency') || str_contains($combined, 'er') => 'call_emergency',
            default => null,
        };
    }

    private function tutorialCards(string $intent, string $audience): array
    {
        if ($audience === 'resident') {
            return match ($intent) {
                'appointment' => [
                    ['title' => 'Book Appointment', 'body' => 'Open Appointments, tap Create, fill in concern/date, then submit.'],
                ],
                'id_verification' => [
                    ['title' => 'Verify ID', 'body' => 'Open Profile, tap ID Verification, upload a clear ID photo.'],
                ],
                'records' => [
                    ['title' => 'View Records', 'body' => 'Open Consultations or Records to view previous visits and notes.'],
                ],
                default => [],
            };
        }

        return match ($intent) {
            'queue' => [
                ['title' => 'Queue Step 1', 'body' => 'Open Queue page and choose the active station.'],
                ['title' => 'Queue Step 2', 'body' => 'Click Call Next, then Serving, then Done.'],
            ],
            'cms' => [
                ['title' => 'CMS Step 1', 'body' => 'Open Announcements or Events.'],
                ['title' => 'CMS Step 2', 'body' => 'Create post, add image, then publish to mobile.'],
            ],
            'users' => [
                ['title' => 'User Checking', 'body' => 'Open Users page, filter pending accounts, review info, then approve/reject.'],
            ],
            'sms' => [
                ['title' => 'SMS Campaign', 'body' => 'Select target demographics, preview recipients, then send.'],
            ],
            default => [
                ['title' => 'RHU Staff Tutorial', 'body' => 'Ask about Queue, Appointments, CMS, SMS, Users, Inventory, or Analytics.'],
            ],
        };
    }

    private function detectLanguage(string $message): string
    {
        $text = mb_strtolower($message);

        if (preg_match('/\b(ed|may|so|saray|baley|maung|anggapo)\b/u', $text)) {
            return 'pag';
        }

        if (preg_match('/\b(ako|ikaw|paano|sakit|lagnat|gamot|pila)\b/u', $text)) {
            return 'tl';
        }

        return 'en';
    }

    private function safeFallback(string $audience): string
    {
        if ($audience === 'staff') {
            return 'May temporary issue ang AI assistant. Pwede pa rin kitang tulungan sa RHU modules: Queue, Appointments, Consultations, CMS, SMS, Users, Inventory, Analytics, at Settings.';
        }

        return 'May temporary issue ang AI assistant. Pwede pa rin kitang gabayan sa appointments, records, events, telemedicine, at ID verification. Kung emergency, pumunta agad sa ER.';
    }
}