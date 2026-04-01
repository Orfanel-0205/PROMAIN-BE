<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsultationController extends Controller
{
    public function index(): JsonResponse
    {
        $consultations = Consultation::with(['resident', 'attendant'])->latest()->paginate(20);
        return response()->json($consultations);
    }

    public function mine(Request $request): JsonResponse
    {
        $consultations = Consultation::where('user_id', $request->user()->user_id)->latest()->paginate(15);
        return response()->json($consultations);
    }

    public function show(int $id): JsonResponse
    {
        $consultation = Consultation::with(['resident', 'attendant', 'medicalReports'])->findOrFail($id);
        return response()->json(['consultation' => $consultation]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'           => 'required|exists:users,user_id',
            'consultation_date' => 'required|date',
            'chief_complaint'   => 'nullable|string',
            'diagnosis'         => 'nullable|string',
            'treatment'         => 'nullable|string',
        ]);

        $consultation = Consultation::create([
            ...$validated,
            'attended_by' => $request->user()->user_id,
            'status'      => 'open',
        ]);

        return response()->json(['message' => 'Consultation created.', 'consultation' => $consultation], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::findOrFail($id);

        $validated = $request->validate([
            'chief_complaint' => 'nullable|string',
            'diagnosis'       => 'nullable|string',
            'treatment'       => 'nullable|string',
            'status'          => 'sometimes|in:open,completed',
        ]);

        $consultation->update($validated);

        return response()->json(['message' => 'Consultation updated.', 'consultation' => $consultation]);
    }
}