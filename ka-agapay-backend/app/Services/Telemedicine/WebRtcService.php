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
            return [
                'room_id' => $session->room_id,
                'room_token' => $session->room_token,
                'ice_servers' => $session->ice_servers ?? $this->getIceServers(),
            ];
        }

        return $this->createRoom($session);
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