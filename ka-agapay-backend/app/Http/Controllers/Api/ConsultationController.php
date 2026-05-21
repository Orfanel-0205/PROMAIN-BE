<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsultationController extends Controller
{
    /**
     * GET /v1/consultations
     * Admin — all consultations (web panel use only, not called by mobile)
     */
    public function index(): JsonResponse
    {
        $consultations = Consultation::with(['resident', 'attendant'])
            ->latest()
            ->paginate(20);

        return response()->json($consultations);
    }

    /**
     * GET /v1/consultations  (mobile patient app)
     * Returns only the logged-in patient's consultation history
     * with the shape ProfileScreen expects.
     */
    public function mine(Request $request): JsonResponse
    {
        $perPage = $request->integer('per_page', 15);

        $consultations = Consultation::with('attendant')
            ->where('user_id', $request->user()->user_id)
            ->latest()
            ->paginate($perPage);

        $items = $consultations->getCollection()->map(fn($c) => [
            'id'              => $c->id,
            'doctor_name'     => $c->attendant?->full_name
                                 ?? ($c->attended_by ? 'Dr. #' . $c->attended_by : 'RHU Doctor'),
            'specialty'       => 'General Medicine',
            'date'            => $c->consultation_date
                                 ?? $c->created_at->toDateString(),
            'chief_complaint' => $c->chief_complaint ?? '—',
            'diagnosis'       => $c->diagnosis        ?? null,
            'prescription'    => $c->treatment        ?? null,
            'status'          => $c->status           ?? 'completed',
        ]);

        return response()->json([
            'data'  => $items,
            'meta'  => [
                'current_page' => $consultations->currentPage(),
                'last_page'    => $consultations->lastPage(),
                'per_page'     => $consultations->perPage(),
                'total'        => $consultations->total(),
                'from'         => $consultations->firstItem() ?? 0,
                'to'           => $consultations->lastItem()  ?? 0,
                'path'         => $request->url(),
                'links'        => [],
            ],
            'links' => [
                'first' => $consultations->url(1),
                'last'  => $consultations->url($consultations->lastPage()),
                'prev'  => $consultations->previousPageUrl(),
                'next'  => $consultations->nextPageUrl(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $consultation = Consultation::with(['resident', 'attendant', 'medicalReports'])
            ->findOrFail($id);

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

        return response()->json([
            'message'      => 'Consultation created.',
            'consultation' => $consultation,
        ], 201);
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

        return response()->json([
            'message'      => 'Consultation updated.',
            'consultation' => $consultation,
        ]);
    }
}