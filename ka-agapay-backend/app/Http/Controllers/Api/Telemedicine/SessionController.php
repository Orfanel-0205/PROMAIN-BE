<?php

namespace App\Http\Controllers\Api\Telemedicine;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function create(Request $request, $id): JsonResponse
    {
        return response()->json(['message' => 'Session created.'], 201);
    }

    public function show($id): JsonResponse
    {
        return response()->json(['data' => null]);
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        return response()->json(['message' => 'Status updated.']);
    }

    public function saveNotes(Request $request, $id): JsonResponse
    {
        return response()->json(['message' => 'Notes saved.']);
    }
}
