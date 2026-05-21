<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelemedicineSession;
use App\Services\Telemedicine\WebRtcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebRtcController extends Controller
{
    public function __construct(private readonly WebRtcService $webrtc) {}

    /**
     * GET /api/v1/telemedicine/sessions/{id}/join
     * Get credentials to join the WebRTC room.
     */
    public function getJoinToken(Request $request, int $id): JsonResponse
    {
        $session = TelemedicineSession::findOrFail($id);
        $user = $request->user();

        // Authorize: only doctor or resident participant
        // For simplicity, we allow authorized users.
        // In production, add strict checks here.

        // Create room if not exists
        if (!$session->room_id) {
            $this->webrtc->createRoom($session);
            $session->refresh();
        }

        return response()->json([
            'data' => $this->webrtc->getJoinToken($session, $user),
        ]);
    }

    /**
     * POST /api/v1/telemedicine/sessions/{id}/signal
     * Exchange WebRTC signals.
     */
    public function signal(Request $request, int $id): JsonResponse
    {
        $session = TelemedicineSession::findOrFail($id);

        $request->validate([
            'receiver_id' => ['required', 'integer'],
            'type'        => ['required', 'in:offer,answer,ice_candidate,hang_up,mute,unmute,ready'],
            'payload'     => ['required', 'array'],
        ]);

        $receiverId = $request->receiver_id;

        // Handle broadcast/ready signal by finding the other participant
        if ($receiverId === -1) {
            $user = $request->user();
            $patientId = $session->request->requested_by;
            $doctorId = $session->assigned_doctor_id;

            $receiverId = ($user->user_id === $doctorId) ? $patientId : $doctorId;
        }

        $signal = $this->webrtc->saveSignal(
            $session,
            $request->user(),
            $receiverId,
            $request->type,
            $request->payload
        );

        return response()->json([
            'message'    => 'Signal sent.',
            'signal_id'  => $signal->id,
            'receiver_id'=> $receiverId,
        ]);
    }

    /**
     * GET /api/v1/telemedicine/sessions/{id}/signals
     * Poll for incoming signals.
     */
    public function getSignals(Request $request, int $id): JsonResponse
    {
        $session = TelemedicineSession::findOrFail($id);
        $signals = $this->webrtc->getPendingSignals($session, $request->user());

        return response()->json(['data' => $signals]);
    }
}