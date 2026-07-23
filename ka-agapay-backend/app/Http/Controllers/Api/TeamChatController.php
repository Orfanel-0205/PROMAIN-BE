<?php
// app/Http/Controllers/Api/TeamChatController.php
//
// Team Chat — internal staff-to-staff messaging (web admin only, never
// resident-facing). Load-safe by design:
//   • poll endpoint (updates) is a cheap unread-delta query, no history refetch
//   • thread reads are cursor-paginated (before_id), never the full history
//   • send endpoint carries its own throttle (see routes/api.php)
// RHU scoping: staff may only message coworkers in their own facility; a
// global-scope user (Super Admin / MHO) may span both RHU 1 and RHU 2.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Support\Rhu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TeamChatController extends Controller
{
    /** Roles that are NOT staff (residents) — excluded from Team Chat contacts. */
    private const RESIDENT_ROLES = ['resident', 'patient'];

    // =====================================================================
    // CONTACTS — staff this user is allowed to start a conversation with
    // =====================================================================

    public function contacts(Request $request): JsonResponse
    {
        $me = $request->user();
        $search = trim((string) $request->query('q', ''));

        $candidates = User::query()
            ->with('role')
            ->where('user_id', '!=', $me->user_id)
            ->where('account_status', 'active')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('first_name', 'ILIKE', $like)
                        ->orWhere('last_name', 'ILIKE', $like);
                });
            })
            ->orderBy('first_name')
            ->limit(200)
            ->get()
            ->filter(fn (User $u) => !$this->isResident($u) && $this->canMessage($me, $u))
            ->values()
            ->map(fn (User $u) => $this->userBrief($u));

        return response()->json(['data' => $candidates]);
    }

    // =====================================================================
    // CONVERSATION LIST (paginated) — left panel
    // =====================================================================

    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $perPage = min(50, max(5, (int) $request->query('per_page', 20)));

        $conversations = Conversation::query()
            ->whereHas('participants', function ($q) use ($me) {
                $q->where('user_id', $me->user_id)->whereNull('left_at');
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $conversations->getCollection()->transform(
            fn (Conversation $c) => $this->conversationSummary($c, $me)
        );

        return response()->json([
            'data' => $conversations->items(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'total' => $conversations->total(),
            ],
            'total_unread' => $this->totalUnread($me),
        ]);
    }

    // =====================================================================
    // POLL — cheap "what changed since my last-seen" delta
    // =====================================================================

    public function updates(Request $request): JsonResponse
    {
        $me = $request->user();

        // Only conversations touched since the client's high-water mark, so an
        // idle panel returns an (almost) empty payload every tick.
        $sinceId = (int) $request->query('since_id', 0);

        $conversations = Conversation::query()
            ->whereHas('participants', function ($q) use ($me) {
                $q->where('user_id', $me->user_id)->whereNull('left_at');
            })
            ->when($sinceId > 0, function ($q) use ($sinceId) {
                $q->whereHas('messages', fn ($m) => $m->where('id', '>', $sinceId));
            })
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get()
            ->map(fn (Conversation $c) => $this->conversationSummary($c, $me));

        $payload = [
            'data' => $conversations,
            'total_unread' => $this->totalUnread($me),
            'server_time' => now()->toISOString(),
        ];

        // Piggyback the OPEN conversation's new-message tail onto this same
        // response, so the client makes ONE request per poll tick instead of two
        // — the difference between staying under and blowing past the per-user
        // rate limit. Only returns messages the participant is entitled to see.
        $activeId = (int) $request->query('active_id', 0);
        $activeAfterId = (int) $request->query('active_after_id', 0);
        if ($activeId > 0) {
            $participant = ConversationParticipant::query()
                ->where('conversation_id', $activeId)
                ->where('user_id', $me->user_id)
                ->whereNull('left_at')
                ->first();

            if ($participant) {
                $tail = Message::query()
                    ->where('conversation_id', $activeId)
                    ->where('id', '>', $activeAfterId)
                    ->orderBy('id')
                    ->limit(100)
                    ->get();

                $payload['active_conversation_id'] = $activeId;
                $payload['active_messages'] = $tail->map(fn (Message $m) => $this->messagePayload($m));
            }
        }

        return response()->json($payload);
    }

    // =====================================================================
    // THREAD — cursor-paginated messages (never the full history)
    // =====================================================================

    public function show(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $convo = Conversation::findOrFail($conversation);
        $this->ensureParticipant($convo, $me);

        // Real-time tail: return ONLY messages newer than what the client already
        // has. This is the query the open thread polls — a cheap indexed scan on
        // (conversation_id, id) that returns an empty array when nothing is new,
        // instead of re-fetching the whole visible history every tick.
        $afterId = (int) $request->query('after_id', 0);
        if ($afterId > 0) {
            $rows = Message::query()
                ->where('conversation_id', $convo->id)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit(100)
                ->get();

            return response()->json([
                'data' => $rows->map(fn (Message $m) => $this->messagePayload($m)),
                'has_more' => false,
            ]);
        }

        $limit = min(50, max(10, (int) $request->query('limit', 30)));
        $beforeId = (int) $request->query('before_id', 0);

        $query = Message::query()
            ->where('conversation_id', $convo->id)
            ->when($beforeId > 0, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit + 1);

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->reverse()->values();

        return response()->json([
            'data' => $rows->map(fn (Message $m) => $this->messagePayload($m)),
            'has_more' => $hasMore,
            'conversation' => $this->conversationSummary($convo, $me),
        ]);
    }

    // =====================================================================
    // CREATE — DM (idempotent per pair) or group
    // =====================================================================

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();

        $validated = $request->validate([
            'type' => ['required', 'in:dm,group'],
            'target_id' => ['required_if:type,dm', 'integer'],
            'title' => ['required_if:type,group', 'nullable', 'string', 'max:150'],
            'participant_ids' => ['required_if:type,group', 'array'],
            'participant_ids.*' => ['integer'],
        ]);

        if ($validated['type'] === 'dm') {
            $target = User::findOrFail($validated['target_id']);

            abort_if($this->isResident($target), 422, 'You can only message staff members.');
            abort_unless($this->canMessage($me, $target), 403, 'That staff member is in a different RHU.');

            $dmKey = Conversation::dmKeyFor((int) $me->user_id, (int) $target->user_id);

            $convo = Conversation::withTrashed()->where('dm_key', $dmKey)->first();

            if ($convo) {
                if ($convo->trashed()) {
                    $convo->restore();
                }
                // Re-activate my participation if I had left.
                $this->ensureParticipantRow($convo, (int) $me->user_id);
                $this->ensureParticipantRow($convo, (int) $target->user_id);
            } else {
                $convo = DB::transaction(function () use ($me, $target, $dmKey) {
                    $c = Conversation::create([
                        'type' => 'dm',
                        'rhu_id' => Rhu::isGlobalScope($me) ? Rhu::resolveRhuIdFromUser($target) : Rhu::resolveRhuIdFromUser($me),
                        'dm_key' => $dmKey,
                        'created_by' => $me->user_id,
                    ]);
                    $this->ensureParticipantRow($c, (int) $me->user_id);
                    $this->ensureParticipantRow($c, (int) $target->user_id);
                    return $c;
                });
            }

            return response()->json(['data' => $this->conversationSummary($convo->fresh(), $me)], 201);
        }

        // Group
        $ids = collect($validated['participant_ids'])
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === (int) $me->user_id)
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 422, 'Add at least one other staff member to the group.');

        $members = User::whereIn('user_id', $ids)->get();

        foreach ($members as $member) {
            abort_if($this->isResident($member), 422, 'Groups may only contain staff members.');
            abort_unless($this->canMessage($me, $member), 403, 'All group members must be in your RHU.');
        }

        $convo = DB::transaction(function () use ($me, $members, $validated) {
            $c = Conversation::create([
                'type' => 'group',
                'title' => $validated['title'],
                'rhu_id' => Rhu::resolveRhuIdFromUser($me),
                'created_by' => $me->user_id,
            ]);
            $this->ensureParticipantRow($c, (int) $me->user_id);
            foreach ($members as $member) {
                $this->ensureParticipantRow($c, (int) $member->user_id);
            }
            return $c;
        });

        return response()->json(['data' => $this->conversationSummary($convo->fresh(), $me)], 201);
    }

    // =====================================================================
    // SEND MESSAGE (throttled in routes)
    // =====================================================================

    public function sendMessage(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $convo = Conversation::findOrFail($conversation);
        $this->ensureParticipant($convo, $me);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachment_path' => ['nullable', 'string', 'max:500'],
            'attachment_meta' => ['nullable', 'array'],
        ]);

        $body = trim((string) ($validated['body'] ?? ''));
        $attachment = $validated['attachment_path'] ?? null;

        abort_if($body === '' && !$attachment, 422, 'Message cannot be empty.');

        // An attachment_path must be one this endpoint issued (chat/attachments)
        // so a client cannot reference an arbitrary stored file.
        if ($attachment && !str_starts_with($attachment, 'chat/attachments/')) {
            abort(422, 'Invalid attachment reference.');
        }

        $message = DB::transaction(function () use ($convo, $me, $body, $attachment, $validated) {
            $msg = Message::create([
                'conversation_id' => $convo->id,
                'sender_id' => $me->user_id,
                'body' => $body !== '' ? $body : null,
                'attachment_path' => $attachment,
                'attachment_meta' => $validated['attachment_meta'] ?? null,
            ]);

            $convo->forceFill(['last_message_at' => $msg->created_at])->save();

            // The sender has, by definition, read their own message.
            ConversationParticipant::where('conversation_id', $convo->id)
                ->where('user_id', $me->user_id)
                ->update(['last_read_message_id' => $msg->id]);

            return $msg;
        });

        return response()->json(['data' => $this->messagePayload($message)], 201);
    }

    // =====================================================================
    // MARK READ
    // =====================================================================

    /**
     * Lightweight global unread total for the sidebar badge. One indexed
     * aggregate — safe to poll app-wide alongside the existing count refresh.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $this->totalUnread($request->user())]);
    }

    public function markRead(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $convo = Conversation::findOrFail($conversation);
        $this->ensureParticipant($convo, $me);

        $latestId = (int) Message::where('conversation_id', $convo->id)->max('id');

        ConversationParticipant::where('conversation_id', $convo->id)
            ->where('user_id', $me->user_id)
            ->update(['last_read_message_id' => $latestId]);

        return response()->json(['data' => ['last_read_message_id' => $latestId], 'total_unread' => $this->totalUnread($me)]);
    }

    // =====================================================================
    // SEARCH — participant-scoped, paginated (Part 3)
    // =====================================================================

    public function search(Request $request): JsonResponse
    {
        $me = $request->user();
        $term = trim((string) $request->query('q', ''));
        $perPage = min(50, max(5, (int) $request->query('per_page', 20)));

        if ($term === '') {
            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]);
        }

        // Privacy boundary: only conversations the searcher actually belongs to.
        $myConversationIds = ConversationParticipant::where('user_id', $me->user_id)
            ->whereNull('left_at')
            ->pluck('conversation_id');

        $results = Message::query()
            ->whereIn('conversation_id', $myConversationIds)
            ->whereNotNull('body')
            ->where('body', 'ILIKE', '%' . $term . '%')
            ->orderByDesc('id')
            ->paginate($perPage);

        $results->getCollection()->transform(function (Message $m) {
            $payload = $this->messagePayload($m);
            $payload['conversation_id'] = $m->conversation_id;
            return $payload;
        });

        return response()->json([
            'data' => $results->items(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'total' => $results->total(),
            ],
        ]);
    }

    // =====================================================================
    // IMAGE ATTACHMENT UPLOAD (Part 4) — reuses the 'public' disk convention
    // =====================================================================

    public function uploadAttachment(Request $request): JsonResponse
    {
        $request->validate([
            // 8 MB: a chat photo needs no OCR (unlike the 5 MB Employee-ID cap),
            // but must stay bounded. 8192 KB = 8 MB.
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'image.max' => 'The image must not be larger than 8 MB.',
            'image.mimes' => 'Only JPG, PNG, or WebP images are accepted.',
        ]);

        $file = $request->file('image');
        $path = $file->store('chat/attachments', 'public');

        return response()->json([
            'data' => [
                'attachment_path' => $path,
                'url' => Storage::disk('public')->url($path),
                'attachment_meta' => [
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'name' => $file->getClientOriginalName(),
                ],
            ],
        ], 201);
    }

    // =====================================================================
    // GROUP: add participants / leave (soft)
    // =====================================================================

    public function addParticipants(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $convo = Conversation::findOrFail($conversation);
        $this->ensureParticipant($convo, $me);
        abort_unless($convo->type === 'group', 422, 'Only group conversations can add members.');

        $validated = $request->validate([
            'participant_ids' => ['required', 'array'],
            'participant_ids.*' => ['integer'],
        ]);

        $members = User::whereIn('user_id', $validated['participant_ids'])->get();

        foreach ($members as $member) {
            abort_if($this->isResident($member), 422, 'Groups may only contain staff members.');
            abort_unless($this->canMessage($me, $member), 403, 'All group members must be in your RHU.');
            $this->ensureParticipantRow($convo, (int) $member->user_id);
        }

        return response()->json(['data' => $this->conversationSummary($convo->fresh(), $me)]);
    }

    public function leave(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $convo = Conversation::findOrFail($conversation);
        $this->ensureParticipant($convo, $me);

        ConversationParticipant::where('conversation_id', $convo->id)
            ->where('user_id', $me->user_id)
            ->update(['left_at' => now()]);

        return response()->json(['data' => ['left' => true]]);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function isResident(User $user): bool
    {
        return in_array(strtolower((string) $user->role_name), self::RESIDENT_ROLES, true);
    }

    /** Within-RHU rule: global-scope users span both; everyone else same facility. */
    private function canMessage(User $me, User $target): bool
    {
        if (Rhu::isGlobalScope($me)) {
            return true;
        }

        return Rhu::resolveRhuIdFromUser($me) === Rhu::resolveRhuIdFromUser($target);
    }

    private function ensureParticipant(Conversation $convo, User $user): ConversationParticipant
    {
        $participant = ConversationParticipant::where('conversation_id', $convo->id)
            ->where('user_id', $user->user_id)
            ->whereNull('left_at')
            ->first();

        abort_unless($participant, 403, 'You are not a participant in this conversation.');

        return $participant;
    }

    private function ensureParticipantRow(Conversation $convo, int $userId): void
    {
        $existing = ConversationParticipant::where('conversation_id', $convo->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            if ($existing->left_at !== null) {
                $existing->update(['left_at' => null]);
            }
            return;
        }

        ConversationParticipant::create([
            'conversation_id' => $convo->id,
            'user_id' => $userId,
        ]);
    }

    private function totalUnread(User $me): int
    {
        // Sum of per-conversation unread across my active memberships.
        return (int) DB::table('conversation_participants as cp')
            ->join('messages as m', 'm.conversation_id', '=', 'cp.conversation_id')
            ->where('cp.user_id', $me->user_id)
            ->whereNull('cp.left_at')
            ->whereNull('m.deleted_at')
            ->where('m.sender_id', '!=', $me->user_id)
            ->whereRaw('m.id > COALESCE(cp.last_read_message_id, 0)')
            ->count();
    }

    private function conversationSummary(Conversation $convo, User $me): array
    {
        $participants = ConversationParticipant::with('user.role')
            ->where('conversation_id', $convo->id)
            ->get();

        $myRow = $participants->firstWhere('user_id', (int) $me->user_id);
        $lastReadId = (int) ($myRow->last_read_message_id ?? 0);

        $lastMessage = Message::where('conversation_id', $convo->id)
            ->orderByDesc('id')
            ->first();

        $unread = (int) Message::where('conversation_id', $convo->id)
            ->where('id', '>', $lastReadId)
            ->where('sender_id', '!=', $me->user_id)
            ->count();

        // For a DM, the display name/avatar is the OTHER participant.
        $other = null;
        if ($convo->type === 'dm') {
            $otherRow = $participants->firstWhere('user_id', '!=', (int) $me->user_id);
            $other = $otherRow?->user ? $this->userBrief($otherRow->user) : null;
        }

        return [
            'id' => $convo->id,
            'type' => $convo->type,
            'title' => $convo->type === 'group'
                ? $convo->title
                : ($other['name'] ?? 'Direct message'),
            'avatar' => $other['avatar'] ?? null,
            'rhu_id' => $convo->rhu_id,
            'participants' => $participants
                ->filter(fn ($p) => $p->user)
                ->map(fn ($p) => $this->userBrief($p->user))
                ->values(),
            'participant_count' => $participants->whereNull('left_at')->count(),
            'last_message' => $lastMessage ? [
                'id' => $lastMessage->id,
                'preview' => $lastMessage->body
                    ? \Illuminate\Support\Str::limit($lastMessage->body, 60)
                    : '📷 Photo',
                'sender_id' => $lastMessage->sender_id,
                'created_at' => optional($lastMessage->created_at)->toISOString(),
            ] : null,
            'last_message_at' => optional($convo->last_message_at)->toISOString(),
            'unread_count' => $unread,
        ];
    }

    private function messagePayload(Message $m): array
    {
        return [
            'id' => $m->id,
            'conversation_id' => $m->conversation_id,
            'sender_id' => $m->sender_id,
            'body' => $m->body,
            'attachment_url' => $m->attachment_path
                ? Storage::disk('public')->url($m->attachment_path)
                : null,
            'attachment_meta' => $m->attachment_meta,
            'created_at' => optional($m->created_at)->toISOString(),
        ];
    }

    private function userBrief(User $u): array
    {
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));

        return [
            'id' => (int) $u->user_id,
            'name' => $name !== '' ? $name : ('User #' . $u->user_id),
            'role' => $u->role_name,
            'avatar' => $this->avatarUrl($u),
            'rhu_id' => Rhu::resolveRhuIdFromUser($u),
        ];
    }

    /**
     * Resolve a staff member's profile picture to a full URL so the chat can
     * render it directly (the column stores a 'public' disk path, not a URL).
     * Falls through avatar → profile_picture and passes absolute URLs untouched.
     */
    private function avatarUrl(User $u): ?string
    {
        $path = $u->avatar ?: ($u->profile_picture ?? null);

        if (!$path) {
            return null;
        }

        return str_starts_with((string) $path, 'http')
            ? (string) $path
            : Storage::disk('public')->url($path);
    }
}
