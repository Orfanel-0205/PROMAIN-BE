<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicalReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'         => 'required|exists:users,user_id',
            'consultation_id' => 'nullable|exists:consultations,id',
            'report_type'     => 'nullable|string|max:100',
            'findings'        => 'nullable|string',
            'recommendations' => 'nullable|string',
        ]);

        $report = MedicalReport::create([
            ...$validated,
            'created_by' => $request->user()->user_id,
        ]);

        return response()->json(['message' => 'Medical report created.', 'report' => $report], 201);
    }

    public function show(int $id): JsonResponse
    {
        $report = MedicalReport::with(['resident', 'consultation', 'creator'])->findOrFail($id);
        return response()->json(['report' => $report]);
    }

    public function forResident(int $userId): JsonResponse
    {
        $reports = MedicalReport::where('user_id', $userId)->latest()->paginate(15);
        return response()->json($reports);
    }
}