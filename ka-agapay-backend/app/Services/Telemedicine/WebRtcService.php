<?php
// app/Services/Telemedicine/WebRtcService.php

namespace App\Services\Telemedicine;

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
    public function buildRoomConfig(TelemedicineSession $session): array
    {
        $provider = (string) config('services.jitsi.provider', 'self_hosted');
        $configuredDomain = (string) config('services.jitsi.domain', 'meet.kaagapay.local');
        $prefix = (string) config('services.jitsi.room_prefix', 'kaagapay-rhu1');
        $appId = (string) config('services.jitsi.app_id', '');
        $jwtEnabled = (bool) config('services.jitsi.jwt_enabled', false);

        $isDemo = $provider === 'meet_public_demo';
        $domain = $isDemo ? 'meet.jit.si' : $configuredDomain;

        // Stable, non-PII room name: kaagapay-rhu1-session-{id}-{safeToken}
        $safeSeed = ($session->session_token ?: $session->room_token ?: 'room')
            . ':' . $session->id . ':' . config('app.key');
        $safeToken = substr(hash('sha256', $safeSeed), 0, 12);

        $roomName = $prefix . '-session-' . $session->id . '-' . $safeToken;
        $roomName = preg_replace('/[^a-zA-Z0-9_-]/', '', $roomName);

        // JaaS namespaces rooms under the tenant/app id.
        $fullRoom = ($provider === 'jaas' && $appId !== '')
            ? $appId . '/' . $roomName
            : $roomName;

        $joinUrl = 'https://' . $domain . '/' . $fullRoom
            . '#config.prejoinPageEnabled=false&config.disableDeepLinking=true';

        $jwt = null;

        if ($jwtEnabled && !$isDemo) {
            $jwt = $this->buildJitsiJwt($session, $roomName, $provider);
        }

        return [
            'provider'     => $provider,
            'domain'       => $domain,
            'room_name'    => $fullRoom,
            'room'         => $fullRoom,
            'room_url'     => $joinUrl,
            'join_url'     => $joinUrl,
            'jwt'          => $jwt,
            'jwt_enabled'  => $jwtEnabled,
            'is_demo'      => $isDemo,
            'demo_warning' => $isDemo
                ? 'Demo video provider: meetings may disconnect after 5 minutes.'
                : null,
            'configured'   => $this->isProviderConfigured($provider, $domain, $jwtEnabled, $jwt, $appId),
        ];
    }

    private function isProviderConfigured(
        string $provider,
        string $domain,
        bool $jwtEnabled,
        ?string $jwt,
        string $appId
    ): bool {
        if ($provider === 'meet_public_demo') {
            return true;
        }

        if ($provider === 'self_hosted') {
            return $domain !== '' && $domain !== 'meet.kaagapay.local'
                && (!$jwtEnabled || !empty($jwt));
        }

        if ($provider === 'jaas') {
            return $appId !== '' && (!$jwtEnabled || !empty($jwt));
        }

        return false;
    }

    private function buildJitsiJwt(
        TelemedicineSession $session,
        string $roomName,
        string $provider
    ): ?string {
        if (!class_exists(\Firebase\JWT\JWT::class)) {
            return null;
        }

        try {
            $appId = (string) config('services.jitsi.app_id', '');
            $appSecret = (string) config('services.jitsi.app_secret', '');
            $apiKey = (string) config('services.jitsi.api_key', '');     // JaaS kid
            $privateKey = (string) config('services.jitsi.private_key', '');
            $domain = (string) config('services.jitsi.domain', '');

            $now = time();

            $session->loadMissing(['assignedDoctor']);
            $doctor = $session->assignedDoctor;

            $name = trim(
                (string) ($doctor->first_name ?? '') . ' ' .
                (string) ($doctor->last_name ?? '')
            ) ?: 'RHU Clinician';

            $payload = [
                'aud'  => $provider === 'jaas' ? 'jitsi' : ($appId ?: 'kaagapay'),
                'iss'  => $provider === 'jaas' ? 'chat' : ($appId ?: 'kaagapay'),
                'sub'  => $provider === 'jaas' ? $appId : ($domain ?: 'kaagapay'),
                'room' => $provider === 'jaas' ? '*' : $roomName,
                'nbf'  => $now - 10,
                'exp'  => $now + 7200,
                'context' => [
                    'user' => [
                        'id'        => (string) ($session->assigned_doctor_id ?? 'staff'),
                        'name'      => $name,
                        'moderator' => true,
                    ],
                    'features' => [
                        'recording'     => false,
                        'livestreaming' => false,
                        'transcription' => false,
                    ],
                ],
            ];

            if ($provider === 'jaas') {
                if ($privateKey === '' || $apiKey === '') {
                    return null;
                }

                return \Firebase\JWT\JWT::encode($payload, $privateKey, 'RS256', $apiKey);
            }

            // self_hosted: HS256 shared secret token.
            if ($appSecret === '') {
                return null;
            }

            return \Firebase\JWT\JWT::encode($payload, $appSecret, 'HS256');
        } catch (\Throwable $e) {
            logger()->warning('[WebRtcService] Failed to build Jitsi JWT.', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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