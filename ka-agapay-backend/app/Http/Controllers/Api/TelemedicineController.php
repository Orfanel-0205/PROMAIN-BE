<?php
// app/Http/Controllers/Api/TelemedicineController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelemedicineSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelemedicineController extends Controller
{
    /**
     * GET /api/v1/telemedicine/sessions
     * List sessions for the authenticated patient.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // For testing: if no real sessions exist, return a mock one
        $sessions = TelemedicineSession::where('patient_id', $user->user_id)
            ->with('doctor')
            ->orderBy('scheduled_at', 'desc')
            ->get()
            ->map(fn($s) => [
                'id'               => $s->id,
                'room_id'          => $s->room_id,
                'doctor_name'      => $s->doctor->name ?? 'Dr. Sample',
                'doctor_specialty' => $s->doctor->specialty ?? 'General Medicine',
                'scheduled_at'     => $s->scheduled_at,
                'status'           => $s->status,
                'duration_minutes' => $s->duration_minutes ?? 30,
            ]);

        return response()->json(['data' => $sessions]);
    }

    /**
     * POST /api/v1/telemedicine/sessions/{session}/join
     * Returns room credentials for the patient to join.
     */
    public function join(Request $request, TelemedicineSession $session): JsonResponse
    {
        $user = $request->user();

        // Verify patient owns this session
        abort_unless($session->patient_id === $user->user_id, 403);

        // Generate a stable room ID based on session — 
        // same room ID = same Jitsi room
        $roomId = $session->room_id 
            ?? 'kaagapay-' . Str::slug($session->id . '-' . date('Ymd'));

        // Update session to active
        $session->update([
            'status'  => 'active',
            'room_id' => $roomId,
        ]);

        return response()->json([
            'room_id'      => $roomId,
            'patient_name' => trim("{$user->first_name} {$user->last_name}"),
            'doctor_name'  => $session->doctor->name ?? 'Doctor',
            'token'        => null, // No JWT needed for public Jitsi rooms
        ]);
    }

    /**
     * POST /api/v1/telemedicine/sessions/{session}/end
     */
    public function end(Request $request, TelemedicineSession $session): JsonResponse
    {
        $session->update(['status' => 'ended']);
        return response()->json(['message' => 'Session ended.']);
    }
}
