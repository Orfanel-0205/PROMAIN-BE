<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(): JsonResponse
    {
        $announcements = Announcement::where('status', 'published')
            ->latest('published_at')
            ->paginate(15);

        return response()->json($announcements);
    }

    public function show(int $id): JsonResponse
    {
        $announcement = Announcement::findOrFail($id);
        return response()->json(['announcement' => $announcement]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body'  => 'required|string',
        ]);

        $announcement = Announcement::create([
            ...$validated,
            'created_by' => $request->user()->user_id,
            'status'     => 'draft',
        ]);

        return response()->json(['message' => 'Announcement created.', 'announcement' => $announcement], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $announcement = Announcement::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'body'  => 'sometimes|string',
        ]);

        $announcement->update($validated);

        return response()->json(['message' => 'Announcement updated.', 'announcement' => $announcement]);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->update([
            'status'       => 'published',
            'published_at' => now(),
        ]);

        return response()->json(['message' => 'Announcement published.', 'announcement' => $announcement]);
    }
}