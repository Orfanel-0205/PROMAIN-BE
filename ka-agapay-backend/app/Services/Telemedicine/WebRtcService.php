<?php

namespace App\Services\Telemedicine;

use App\Models\TelemedicineSession;
use App\Models\WebrtcSignal;
use App\Models\User;
use Illuminate\Support\Str;

class WebRtcService
{
    /**
     * Create a WebRTC room for a session.
     * Returns room credentials needed by both peers.
     */
    public function createRoom(TelemedicineSession $session): array
    {
        $roomId    = 'ka-' . Str::uuid()->toString();
        $roomToken = $this->generateRoomToken($roomId, $session->id);

        $session->update([
            'room_id'    => $roomId,
            'room_token' => $roomToken,
            'ice_servers'=> $this->getIceServers(),
        ]);

        return [
            'room_id'     => $roomId,
            'room_token'  => $roomToken,
            'ice_servers' => $this->getIceServers(),
        ];
    }

    /**
     * Get join token for a specific user.
     */
    public function getJoinToken(TelemedicineSession $session, User $user): array
    {
        $isDoctor = $session->assigned_doctor_id === $user->user_id;

        return [
            'room_id'     => $session->room_id,
            'room_token'  => $session->room_token,
            'user_id'     => $user->user_id,
            'user_name'   => $user->first_name . ' ' . $user->last_name,
            'user_role'   => $user->role?->name,
            'is_initiator'=> $isDoctor,
            // Doctor is always the WebRTC offer initiator
            'ice_servers' => $session->ice_servers ?? $this->getIceServers(),
            'session_id'  => $session->id,
        ];
    }

    /**
     * Save a WebRTC signal to database.
     */
    public function saveSignal(
        TelemedicineSession $session,
        User   $sender,
        int    $receiverId,
        string $type,
        array  $payload
    ): WebrtcSignal {
        return WebrtcSignal::create([
            'session_id'  => $session->id,
            'sender_id'   => $sender->user_id,
            'receiver_id' => $receiverId,
            'signal_type' => $type,
            'payload'     => $payload,
        ]);
    }

    /**
     * Get pending signals for a user (polling endpoint).
     */
    public function getPendingSignals(TelemedicineSession $session, User $user): array
    {
        $signals = WebrtcSignal::where('session_id', $session->id)
            ->where('receiver_id', $user->user_id)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->orderBy('created_at')
            ->get();

        return $signals->map(fn($s) => [
            'id'          => $s->id,
            'type'        => $s->signal_type,
            'sender_id'   => $s->sender_id,
            'payload'     => $s->payload,
            'created_at'  => $s->created_at->toIso8601String(),
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
        return hash_hmac('sha256', $roomId . ':' . $sessionId, config('app.key'));
    }
}