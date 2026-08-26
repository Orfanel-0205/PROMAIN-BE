<?php
// app/Services/Telemedicine/WebRtcService.php

namespace App\Services\Telemedicine;

use App\Models\ConversationCall;
use App\Services\Video\JitsiTokenService;
use App\Models\TelemedicineSession;
use App\Models\User;
use App\Models\WebrtcSignal;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WebRtcService
{
    public function createRoomIfMissing(TelemedicineSession $session): array
    {
        if ($session->room_id && $session->room_token) {
            return array_merge(
                [
                    'room_id' => $session->room_id,
                    'room_token' => $session->room_token,
                    'ice_servers' => $session->ice_servers ?? $this->getIceServers(),
                ],
                ['video' => $this->buildRoomConfig($session)]
            );
        }

        return array_merge(
            $this->createRoom($session),
            ['video' => $this->buildRoomConfig($session)]
        );
    }

    /**
     * Build the configurable Jitsi video provider payload for a session.
     *
     * The default provider is NEVER public meet.jit.si — that is opt-in via
     * JITSI_PROVIDER=meet_public_demo and is flagged with an admin-only warning
     * because the public demo disconnects after 5 minutes.
     *
     * Room names are stable + privacy-safe: they never contain patient name,
     * diagnosis or complaint.
     */
    public function buildRoomConfig(TelemedicineSession $session, ?User $viewer = null): array
    {
        $tokens = app(JitsiTokenService::class);

        $provider = $tokens->provider();
        $isDemo = $provider === 'meet_public_demo';
        $domain = $isDemo ? 'meet.jit.si' : $tokens->domain();

        // Stable, non-PII room name: kaagapay-rhu1-session-{id}-{safeToken}
        $safeSeed = ($session->session_token ?: $session->room_token ?: 'room')
            . ':' . $session->id . ':' . config('app.key');
        $safeToken = substr(hash('sha256', $safeSeed), 0, 12);

        $roomName = $tokens->roomPrefix() . '-session-' . $session->id . '-' . $safeToken;
        $roomName = preg_replace('/[^a-zA-Z0-9_-]/', '', $roomName);

        // JaaS namespaces rooms under the tenant/app id (unchanged).
        $fullRoom = $tokens->qualifyRoom($roomName);

        $hashParams = [
            'config.prejoinPageEnabled=false',
            'config.disableDeepLinking=true',
        ];

        /*
         * The token now represents WHOEVER is joining, not always the assigned
         * doctor. Previously a resident opening the session got a token that
         * claimed to be the clinician, so everyone showed up under the doctor's
         * name. Falls back to the assigned doctor only when there is no
         * authenticated viewer to name.
         *
         * moderator stays true for every telemedicine participant, matching the
         * previous behaviour — demoting residents would leave them stuck in a
         * JaaS lobby whenever they join before the clinician.
         */
        $jwt = null;

        if (!$isDemo && $tokens->jwtEnabled()) {
            $viewer = $viewer ?: (auth()->user() instanceof User ? auth()->user() : null);

            if (!$viewer) {
                $session->loadMissing(['assignedDoctor']);
                $viewer = $session->assignedDoctor;
            }

            $jwt = $tokens->issueToken($roomName, $tokens->identityForUser($viewer, true));
        }

        return [
            'provider'     => $provider,
            'domain'       => $domain,
            'room_name'    => $fullRoom,
            'room'         => $fullRoom,
            // room_url stays token-free (safe to display/log); join_url is the
            // one to actually open and carries the JWT in the right position.
            'room_url'     => $tokens->buildJoinUrl($domain, $fullRoom, null, $hashParams),
            'join_url'     => $tokens->buildJoinUrl($domain, $fullRoom, $jwt, $hashParams),
            'jwt'          => $jwt,
            'jwt_enabled'  => $tokens->jwtEnabled(),
            'is_demo'      => $isDemo,
            'demo_warning' => $isDemo
                ? 'Demo video provider: meetings may disconnect after 5 minutes.'
                : null,
            'configured'   => $tokens->isUsable($jwt),
        ];
    }

    /**
     * Team Chat calling reuses THIS service - the same configured Jitsi
     * provider, domain, room prefix and JWT settings telemedicine already uses.
     * No second video stack is introduced.
     *
     * The room name is derived from the call id + app key, exactly like the
     * telemedicine room, so it is stable for everyone joining the same call and
     * still carries no staff name, patient name or conversation title.
     */
    public function buildConversationRoomConfig(ConversationCall $call, ?User $joiner = null): array
    {
        $tokens = app(JitsiTokenService::class);

        $provider = $tokens->provider();
        $isDemo = $provider === 'meet_public_demo';
        $domain = $isDemo ? 'meet.jit.si' : $tokens->domain();

        $roomName = $call->room_name !== ''
            ? $call->room_name
            : self::conversationRoomName($call->conversation_id, $call->id);

        $fullRoom = $tokens->qualifyRoom($roomName);

        $hashParams = [
            'config.prejoinPageEnabled=false',
            'config.disableDeepLinking=true',
        ];

        if ($call->mode === 'audio') {
            $hashParams[] = 'config.startWithVideoMuted=true';
        }

        /*
         * A Team Chat call is now a REAL authenticated JaaS join: the token
         * identifies the staff member who is joining. This used to be a
         * hardcoded 'jwt' => null, which is why an authenticated JaaS tenant
         * refused the call.
         *
         * $joiner is only ever passed after the caller has been authorised as
         * an active participant of the conversation (TeamChatController::
         * ensureParticipant), so a token is never minted for an outsider.
         * With no $joiner (e.g. the ringing entry in a poll payload) no token is
         * minted at all — the client fetches one via join/start.
         */
        $jwt = ($joiner && !$isDemo && $tokens->jwtEnabled())
            ? $tokens->issueToken($roomName, $tokens->identityForUser($joiner, true))
            : null;

        return [
            'provider'     => $provider,
            'domain'       => $domain,
            'room_name'    => $fullRoom,
            'room'         => $fullRoom,
            'room_url'     => $tokens->buildJoinUrl($domain, $fullRoom, null, $hashParams),
            'join_url'     => $tokens->buildJoinUrl($domain, $fullRoom, $jwt, $hashParams),
            'jwt'          => $jwt,
            'jwt_enabled'  => $tokens->jwtEnabled(),
            'is_demo'      => $isDemo,
            'demo_warning' => $isDemo
                ? 'Demo video provider: calls may disconnect after 5 minutes.'
                : null,
            // With a $joiner this reports whether THIS person can actually join.
            // Without one it reports provider readiness only.
            'configured'   => $joiner
                ? $tokens->isUsable($jwt)
                : ($tokens->appId() !== '' || $provider !== 'jaas'),
        ];
    }

    /**
     * Stable, privacy-safe room name for a conversation call.
     */
    public static function conversationRoomName(int $conversationId, int $callId): string
    {
        $prefix = (string) config('services.jitsi.room_prefix', 'kaagapay-rhu1');
        $seed = 'chat:' . $conversationId . ':' . $callId . ':' . config('app.key');
        $token = substr(hash('sha256', $seed), 0, 12);

        $room = $prefix . '-chat-' . $callId . '-' . $token;

        return preg_replace('/[^a-zA-Z0-9_-]/', '', $room) ?? $room;
    }

    public function createRoom(TelemedicineSession $session): array
    {
        $roomId = $session->room_id ?: 'ka-' . Str::uuid()->toString();
        $roomToken = $session->room_token ?: $this->generateRoomToken($roomId, (int) $session->id);
        $iceServers = $this->getIceServers();

        $updates = [];

        if (Schema::hasColumn('telemedicine_sessions', 'room_id')) {
            $updates['room_id'] = $roomId;
        }

        if (Schema::hasColumn('telemedicine_sessions', 'room_token')) {
            $updates['room_token'] = $roomToken;
        }

        if (Schema::hasColumn('telemedicine_sessions', 'ice_servers')) {
            $updates['ice_servers'] = $iceServers;
        }

        if (!empty($updates)) {
            $session->update($updates);
            $session->refresh();
        }

        return [
            'room_id' => $roomId,
            'room_token' => $roomToken,
            'ice_servers' => $iceServers,
        ];
    }

    public function getJoinToken(TelemedicineSession $session, User $user): array
    {
        $room = $this->createRoomIfMissing($session);

        $isDoctor = (int) $session->assigned_doctor_id === (int) $user->user_id;

        $name = trim(
            (string) ($user->first_name ?? '') . ' ' .
            (string) ($user->last_name ?? '')
        );

        return [
            'room_id' => $room['room_id'],
            'room_token' => $room['room_token'],
            'user_id' => $user->user_id,
            'user_name' => $name ?: ($user->name ?? $user->email ?? 'Ka-Agapay User'),
            'user_role' => $user->role?->name ?? $user->role_name ?? null,
            'is_initiator' => $isDoctor,
            'ice_servers' => $room['ice_servers'] ?? $this->getIceServers(),
            'session_id' => $session->id,
        ];
    }

    public function saveSignal(
        TelemedicineSession $session,
        User $sender,
        int $receiverId,
        string $type,
        array $payload
    ): WebrtcSignal {
        return WebrtcSignal::create([
            'session_id' => $session->id,
            'sender_id' => $sender->user_id,
            'receiver_id' => $receiverId,
            'signal_type' => $type,
            'payload' => $payload,
        ]);
    }

    public function getPendingSignals(TelemedicineSession $session, User $user): array
    {
        $signals = WebrtcSignal::where('session_id', $session->id)
            ->where('receiver_id', $user->user_id)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->orderBy('created_at')
            ->get();

        return $signals->map(fn ($signal) => [
            'id' => $signal->id,
            'type' => $signal->signal_type,
            'sender_id' => $signal->sender_id,
            'payload' => $signal->payload,
            'created_at' => optional($signal->created_at)->toIso8601String(),
        ])->toArray();
    }

    private function getIceServers(): array
    {
        return [
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun1.l.google.com:19302'],
            ['urls' => 'stun:stun.cloudflare.com:3478'],
        ];
    }

    private function generateRoomToken(string $roomId, int $sessionId): string
    {
        return hash_hmac('sha256', $roomId . ':' . $sessionId, (string) config('app.key'));
    }
}