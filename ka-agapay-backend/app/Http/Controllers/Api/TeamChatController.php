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
use App\Models\ConversationCall;
use App\Models\ConversationCallParticipant;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Services\Telemedicine\WebRtcService;
use App\Support\Rhu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TeamChatController extends Controller
{
    /** Roles that are NOT staff (residents) — excluded from Team Chat contacts. */
    private const RESIDENT_ROLES = ['resident', 'patient'];

    /** A user counts as "online" if seen within this many minutes. */
    private const ONLINE_WINDOW_MINUTES = 3;

    /**
     * Never write the heartbeat more often than this. The chat poll fires every
     * ~4s; without this guard presence alone would be ~15 UPDATEs/min/user.
     */
    private const PRESENCE_WRITE_INTERVAL_SECONDS = 45;

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

        // Presence rides this existing tick — no extra request.
        $this->touchPresence($me);

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

            // Incoming-call ring, piggybacked on the SAME tick rather than a new
            // poller, so calling costs zero additional rate-limit budget.
            'active_calls' => $this->activeCallsFor($me),
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
            // Optional group avatar — a path this app's uploader already issued.
            'image_path' => ['nullable', 'string', 'max:500'],
        ]);

        $groupImage = null;
        if (($validated['image_path'] ?? null)
            && str_starts_with((string) $validated['image_path'], 'chat/')) {
            $groupImage = $validated['image_path'];
        }

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

        $convo = DB::transaction(function () use ($me, $members, $validated, $groupImage) {
            $c = Conversation::create([
                'type' => 'group',
                'title' => $validated['title'],
                'image_path' => $groupImage,
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

            // A new message un-hides the conversation for anyone who had deleted
            // (soft-left) it — so a deleted thread reappears when someone replies.
            ConversationParticipant::where('conversation_id', $convo->id)
                ->whereNotNull('left_at')
                ->update(['left_at' => null]);

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
        $me = $request->user();

        // The sidebar polls this ~every 30s from EVERY page, which makes it the
        // natural app-wide presence heartbeat — again with no new request.
        $this->touchPresence($me);

        return response()->json([
            'unread_count' => $this->totalUnread($me),
            'active_calls' => $this->activeCallsFor($me),
        ]);
    }

    // =====================================================================
    // DELETE MESSAGE (soft content-redaction; sender or Super Admin)
    // =====================================================================

    public function deleteMessage(Request $request, int $conversation, int $message): JsonResponse
    {
        $me = $request->user();
        $convo = Conversation::findOrFail($conversation);
        $this->ensureParticipant($convo, $me);

        $msg = Message::where('conversation_id', $convo->id)->findOrFail($message);

        // SENDER-ONLY by requirement. The earlier build let a Super Admin delete
        // anyone's message; that override was removed on purpose - no role may
        // redact another staff member's words, only the author may.
        $isSender = (int) $msg->sender_id === (int) $me->user_id;

        abort_unless($isSender, 403, 'You can only delete your own messages.');

        // Soft content-redaction: keep the row + original body, hide the content.
        if ($msg->content_deleted_at === null) {
            $msg->forceFill([
                'content_deleted_at' => now(),
                'content_deleted_by' => $me->user_id,
            ])->save();
        }

        return response()->json(['data' => $this->messagePayload($msg->fresh())]);
    }

    // =====================================================================
    // CALLS — voice/video inside a conversation (1:1 and group)
    //
    // Reuses the EXISTING Jitsi integration from Telemedicine (WebRtcService):
    // same configured provider, domain, room prefix. No second video stack.
    // =====================================================================

    /**
     * POST /team-chat/conversations/{c}/call
     *
     * Starts a call, or joins the one already running in this conversation.
     * Idempotent on purpose: two people tapping Call at the same moment must
     * land in the SAME Jitsi room, never two rooms.
     */
    public function startCall(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $convo = Conversation::findOrFail($conversation);
        $this->ensureParticipant($convo, $me);

        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'in:audio,video'],
        ]);

        $mode = $validated['mode'] ?? 'video';

        $call = DB::transaction(function () use ($convo, $me, $mode) {
            $existing = ConversationCall::query()
                ->where('conversation_id', $convo->id)
                ->whereNull('ended_at')
                ->where('started_at', '>', now()->subHour())
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                return $existing;
            }

            $call = ConversationCall::create([
                'conversation_id' => $convo->id,
                'started_by' => $me->user_id,
                'room_name' => '',
                'mode' => $mode,
                'started_at' => now(),
            ]);

            // Room name needs the id, so it is stamped right after insert.
            $call->forceFill([
                'room_name' => WebRtcService::conversationRoomName($convo->id, $call->id),
            ])->save();

            // Everyone still in the thread starts as "ringing".
            $participants = ConversationParticipant::where('conversation_id', $convo->id)
                ->whereNull('left_at')
                ->pluck('user_id');

            foreach ($participants as $userId) {
                ConversationCallParticipant::updateOrCreate(
                    ['call_id' => $call->id, 'user_id' => $userId],
                    (int) $userId === (int) $me->user_id
                        ? ['status' => 'joined', 'joined_at' => now()]
                        : ['status' => 'ringing']
                );
            }

            return $call;
        });

        $this->markCallJoined($call, $me);

        return response()->json(['data' => $this->callPayload($call->fresh(), $me)], 201);
    }

    /**
     * POST /team-chat/calls/{call}/join — recipient accepted.
     */
    public function joinCall(Request $request, int $call): JsonResponse
    {
        $me = $request->user();
        $callRow = ConversationCall::findOrFail($call);
        $convo = Conversation::findOrFail($callRow->conversation_id);
        $this->ensureParticipant($convo, $me);

        abort_if($callRow->ended_at !== null, 409, 'This call has already ended.');

        $this->markCallJoined($callRow, $me);

        return response()->json(['data' => $this->callPayload($callRow->fresh(), $me)]);
    }

    /**
     * POST /team-chat/calls/{call}/decline — recipient dismissed the ring.
     *
     * For a 1:1 call, declining ends it: there is no one else to answer.
     */
    public function declineCall(Request $request, int $call): JsonResponse
    {
        $me = $request->user();
        $callRow = ConversationCall::findOrFail($call);
        $convo = Conversation::findOrFail($callRow->conversation_id);
        $this->ensureParticipant($convo, $me);

        ConversationCallParticipant::updateOrCreate(
            ['call_id' => $callRow->id, 'user_id' => $me->user_id],
            ['status' => 'declined', 'left_at' => now()]
        );

        if ($convo->type === 'dm' && $callRow->ended_at === null) {
            $callRow->forceFill(['ended_at' => now(), 'ended_by' => $me->user_id])->save();
        }

        return response()->json(['data' => $this->callPayload($callRow->fresh(), $me)]);
    }

    /**
     * POST /team-chat/calls/{call}/end — hang up.
     *
     * The row is KEPT (ended_at stamped) as call history; nothing is deleted.
     */
    public function endCall(Request $request, int $call): JsonResponse
    {
        $me = $request->user();
        $callRow = ConversationCall::findOrFail($call);
        $convo = Conversation::findOrFail($callRow->conversation_id);
        $this->ensureParticipant($convo, $me);

        if ($callRow->ended_at === null) {
            $callRow->forceFill([
                'ended_at' => now(),
                'ended_by' => $me->user_id,
            ])->save();
        }

        ConversationCallParticipant::where('call_id', $callRow->id)
            ->where('user_id', $me->user_id)
            ->update(['left_at' => now()]);

        return response()->json(['data' => $this->callPayload($callRow->fresh(), $me)]);
    }

    private function markCallJoined(ConversationCall $call, User $me): void
    {
        ConversationCallParticipant::updateOrCreate(
            ['call_id' => $call->id, 'user_id' => $me->user_id],
            ['status' => 'joined', 'joined_at' => now(), 'left_at' => null]
        );
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
            ->whereNull('content_deleted_at') // never surface deleted content
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
    // GROUP SETTINGS — rename + change icon (group creator, or Super Admin)
    // =====================================================================

    public function updateGroup(Request $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $convo = Conversation::findOrFail($conversation);
        $this->ensureParticipant($convo, $me);

        abort_unless($convo->type === 'group', 422, 'Only group conversations can be edited.');

        // Group admin = its creator; Super Admin may also manage any group.
        $isCreator = (int) $convo->created_by === (int) $me->user_id;
        $isSuperAdmin = $me->hasAnyRole(['super_admin', 'superadmin']);
        abort_unless($isCreator || $isSuperAdmin, 403, 'Only the group creator can change the group.');

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'image_path' => ['nullable', 'string', 'max:500'],
        ]);

        $updates = [];
        if (array_key_exists('title', $validated) && trim((string) $validated['title']) !== '') {
            $updates['title'] = trim($validated['title']);
        }
        if (array_key_exists('image_path', $validated)) {
            $path = $validated['image_path'];
            // Accept a new uploaded image (chat/ path) or null to clear it.
            if ($path === null || $path === '') {
                $updates['image_path'] = null;
            } elseif (str_starts_with((string) $path, 'chat/')) {
                $updates['image_path'] = $path;
            }
        }

        if (!empty($updates)) {
            $convo->forceFill($updates)->save();
        }

        return response()->json(['data' => $this->conversationSummary($convo->fresh(), $me)]);
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

        // Group avatar = its uploaded image (full URL); DM avatar = the other person's.
        $groupAvatar = $convo->image_path
            ? (str_starts_with((string) $convo->image_path, 'http')
                ? $convo->image_path
                : Storage::disk('public')->url($convo->image_path))
            : null;

        return [
            'id' => $convo->id,
            'type' => $convo->type,
            'title' => $convo->type === 'group'
                ? $convo->title
                : ($other['name'] ?? 'Direct message'),
            'avatar' => $convo->type === 'group' ? $groupAvatar : ($other['avatar'] ?? null),
            'rhu_id' => $convo->rhu_id,
            'created_by' => $convo->created_by !== null ? (int) $convo->created_by : null,
            // Whether the viewer may rename / change this group's icon.
            'can_manage' => $convo->type === 'group'
                && ((int) $convo->created_by === (int) $me->user_id
                    || $me->hasAnyRole(['super_admin', 'superadmin'])),
            'participants' => $participants
                ->filter(fn ($p) => $p->user)
                ->map(fn ($p) => $this->userBrief($p->user))
                ->values(),
            'participant_count' => $participants->whereNull('left_at')->count(),

            /*
             * SEEN RECEIPTS — no schema change needed. Each participant already
             * carries last_read_message_id, so "seen" is simply: every message
             * with id <= that value has been read by that person.
             *
             * read_up_to = the highest message id read by EVERY other active
             * participant (the group-safe "Seen" watermark). For a DM that is
             * just the other person's marker. Participants who have left are
             * excluded so a departed member cannot hold the watermark down.
             */
            'read_receipts' => $participants
                ->filter(fn ($p) => (int) $p->user_id !== (int) $me->user_id && $p->left_at === null)
                ->map(fn ($p) => [
                    'user_id' => (int) $p->user_id,
                    'name' => $p->user ? $this->userBrief($p->user)['name'] : null,
                    'last_read_message_id' => (int) ($p->last_read_message_id ?? 0),
                ])
                ->values(),
            'read_up_to' => (int) ($participants
                ->filter(fn ($p) => (int) $p->user_id !== (int) $me->user_id && $p->left_at === null)
                ->min(fn ($p) => (int) ($p->last_read_message_id ?? 0)) ?? 0),
            'last_message' => $lastMessage ? [
                'id' => $lastMessage->id,
                'preview' => $lastMessage->content_deleted_at !== null
                    ? 'This message was deleted'
                    : ($lastMessage->body
                        ? \Illuminate\Support\Str::limit($lastMessage->body, 60)
                        : '📷 Photo'),
                'sender_id' => $lastMessage->sender_id,
                'created_at' => optional($lastMessage->created_at)->toISOString(),
            ] : null,
            'last_message_at' => optional($convo->last_message_at)->toISOString(),
            'unread_count' => $unread,
        ];
    }

    private function messagePayload(Message $m): array
    {
        // A content-deleted message keeps its row (and original body) in the DB
        // but is never returned to the client — only a placeholder — so it shows
        // as "This message was deleted" in the thread.
        $isDeleted = $m->content_deleted_at !== null;

        return [
            'id' => $m->id,
            'conversation_id' => $m->conversation_id,
            'sender_id' => $m->sender_id,
            'body' => $isDeleted ? null : $m->body,
            'attachment_url' => (!$isDeleted && $m->attachment_path)
                ? Storage::disk('public')->url($m->attachment_path)
                : null,
            'attachment_meta' => $isDeleted ? null : $m->attachment_meta,
            'deleted' => $isDeleted,
            'created_at' => optional($m->created_at)->toISOString(),
        ];
    }

    // =====================================================================
    // PRESENCE — heartbeat piggybacked on polls the app already makes
    // =====================================================================

    /**
     * Refresh the caller's last_active_at.
     *
     * Deliberately called from endpoints the app ALREADY polls (the Team Chat
     * updates tick and the sidebar unread-count tick) instead of adding a
     * dedicated heartbeat request — a new poller would eat into the same
     * throttle:60,1 per-user ceiling that previously caused a 429 storm.
     *
     * Writes at most once per PRESENCE_WRITE_INTERVAL_SECONDS, and never fails
     * the request it is riding on.
     */
    private function touchPresence(?User $me): void
    {
        if (!$me || !\Illuminate\Support\Facades\Schema::hasColumn('users', 'last_active_at')) {
            return;
        }

        try {
            $last = $me->last_active_at;

            if ($last && $last->diffInSeconds(now()) < self::PRESENCE_WRITE_INTERVAL_SECONDS) {
                return;
            }

            DB::table('users')
                ->where('user_id', $me->user_id)
                ->update(['last_active_at' => now()]);
        } catch (\Throwable) {
            // Presence is cosmetic; it must never break messaging.
        }
    }

    private function isOnline(?User $u): bool
    {
        if (!$u || !$u->last_active_at) {
            return false;
        }

        return $u->last_active_at->gt(now()->subMinutes(self::ONLINE_WINDOW_MINUTES));
    }

    // =====================================================================
    // CALLS — payload helper
    // =====================================================================

    private function callPayload(ConversationCall $call, ?User $me = null): array
    {
        $starter = User::where('user_id', $call->started_by)->first();

        return [
            'id' => $call->id,
            'conversation_id' => $call->conversation_id,
            'mode' => $call->mode,
            'started_by' => (int) $call->started_by,
            'started_by_name' => $starter ? $this->userBrief($starter)['name'] : 'A staff member',
            'started_by_me' => $me ? ((int) $call->started_by === (int) $me->user_id) : false,
            'started_at' => optional($call->started_at)->toISOString(),
            'ended_at' => optional($call->ended_at)->toISOString(),
            'active' => $call->ended_at === null,
            'video' => app(WebRtcService::class)->buildConversationRoomConfig($call),
        ];
    }

    /**
     * Active calls across the conversations this user still belongs to. This is
     * what turns a poll tick into an "incoming call" ring.
     */
    private function activeCallsFor(User $me): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('conversation_calls')) {
            return [];
        }

        $conversationIds = ConversationParticipant::query()
            ->where('user_id', $me->user_id)
            ->whereNull('left_at')
            ->pluck('conversation_id');

        if ($conversationIds->isEmpty()) {
            return [];
        }

        return ConversationCall::query()
            ->whereIn('conversation_id', $conversationIds)
            ->whereNull('ended_at')
            // A call that was never ended (browser closed mid-call) must not ring
            // forever, so anything older than an hour is treated as stale.
            ->where('started_at', '>', now()->subHour())
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (ConversationCall $call) => $this->callPayload($call, $me))
            ->all();
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
            // Derived presence, not a live socket: "seen within N minutes".
            'is_online' => $this->isOnline($u),
            'last_active_at' => optional($u->last_active_at)->toISOString(),
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
