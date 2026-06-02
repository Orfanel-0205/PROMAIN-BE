<?php
// app/Http/Controllers/Api/HomeVisitController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HomeVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeVisitController extends Controller
{
    // =========================================================================
    // INDEX — GET /home-visits
    // Resident sees only their own requests.
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        $visits = HomeVisit::where('patient_id', $request->user()->user_id)
            ->latest()
            ->get();

        return response()->json(['data' => $visits]);
    }

    // =========================================================================
    // STORE — POST /home-visits
    // Resident requests a home visit.
    // =========================================================================

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scheduled_date'  => ['required', 'date', 'after:today'],
            'address'         => ['required', 'string', 'max:500'],
            'chief_complaint' => ['required', 'string', 'max:1000'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        $visit = HomeVisit::create([
            'patient_id'      => $request->user()->user_id,
            'scheduled_date'  => $data['scheduled_date'],
            'address'         => $data['address'],
            'chief_complaint' => $data['chief_complaint'],
            'notes'           => $data['notes'] ?? null,
            'status'          => 'pending',
        ]);

        return response()->json([
            'message' => 'Home visit request submitted.',
            'data'    => $visit,
        ], 201);
    }

    // =========================================================================
    // SHOW — GET /home-visits/{id}
    // =========================================================================

    public function show(Request $request, int $id): JsonResponse
    {
        $visit = HomeVisit::where('id', $id)
            ->where('patient_id', $request->user()->user_id)
            ->firstOrFail();

        return response()->json(['data' => $visit]);
    }

    // =========================================================================
    // CANCEL — PATCH /home-visits/{id}/cancel
    // Resident can cancel a pending visit only.
    // =========================================================================

    public function cancel(Request $request, int $id): JsonResponse
    {
        $visit = HomeVisit::where('id', $id)
            ->where('patient_id', $request->user()->user_id)
            ->firstOrFail();

        if ($visit->status !== 'pending') {
            return response()->json([
                'message' => "Cannot cancel a visit that is already {$visit->status}.",
            ], 422);
        }

        $visit->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Home visit cancelled.', 'data' => $visit]);
    }
}