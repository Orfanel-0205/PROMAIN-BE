<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'gemini_enabled' => true,
            'triage_threshold' => 0.7,
            'model' => 'gemini-1.5-flash',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Settings updated.']);
    }
}
