<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function myAppointments(Request $request): JsonResponse
    {
        $appointments = Appointment::where('user_id', $request->user()->user_id)
            ->latest()
            ->paginate(15);

        return response()->json($appointments);
    }

    public function index(): JsonResponse
    {
        $appointments = Appointment::with(['resident', 'handler'])->latest()->paginate(20);
        return response()->json($appointments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'appointment_date' => 'required|date|after_or_equal:today',
            'appointment_time' => 'nullable|date_format:H:i',
            'purpose'          => 'nullable|string|max:255',
        ]);

        $appointment = Appointment::create([
            ...$validated,
            'user_id' => $request->user()->user_id,
            'status'  => 'pending',
        ]);

        return response()->json(['message' => 'Appointment created.', 'appointment' => $appointment], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);

        $validated = $request->validate([
            'status'     => 'required|in:pending,confirmed,completed,cancelled',
            'notes'      => 'nullable|string',
            'handled_by' => 'nullable|exists:users,user_id',
        ]);

        $appointment->update($validated);

        return response()->json(['message' => 'Appointment status updated.', 'appointment' => $appointment]);
    }
}