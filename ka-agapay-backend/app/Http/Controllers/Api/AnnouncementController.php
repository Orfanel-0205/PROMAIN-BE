<?php
// app/Http/Controllers/Api/AnnouncementController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    // =========================================================================
    // MOBILE  GET /api/v1/announcements
    // Returns published announcements for the mobile feed.
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        $announcements = Announcement::where('status', 'published')
            ->latest('published_at')
            ->paginate((int) $request->query('per_page', 10));

        $announcements->getCollection()->transform(fn($a) => $this->formatMobile($a));

        return response()->json(['data' => $announcements]);
    }

    public function show(int $id): JsonResponse
    {
        $a = Announcement::where('status', 'published')->findOrFail($id);
        return response()->json(['data' => $this->formatMobile($a)]);
    }

    // =========================================================================
    // ADMIN  GET /api/v1/admin/announcements
    // =========================================================================

    public function adminIndex(Request $request): JsonResponse
    {
        $query = Announcement::with('creator:user_id,first_name,last_name')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(fn($q) => $q->where('title', 'like', $s)->orWhere('body', 'like', $s));
        }

        return response()->json(['data' => $query->paginate(20)]);
    }

    // =========================================================================
    // ADMIN  POST /api/v1/admin/announcements
    // =========================================================================

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'body'        => ['required', 'string'],
            'category'    => ['required', Rule::in(['health_alert', 'program', 'general'])],
            'banner_image'=> ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            'status'      => ['sometimes', Rule::in(['draft', 'published'])],
        ]);

        $bannerPath = null;
        if ($request->hasFile('banner_image')) {
            $bannerPath = $request->file('banner_image')
                ->store('announcements/banners', 'public');
        }

        $announcement = Announcement::create([
            'created_by'   => $request->user()->user_id,
            'title'        => $validated['title'],
            'body'         => $validated['body'],
            'category'     => $validated['category'],
            'banner_image' => $bannerPath,
            'status'       => $validated['status'] ?? 'draft',
            'published_at' => ($validated['status'] ?? 'draft') === 'published' ? now() : null,
        ]);

        return response()->json([
            'message' => 'Announcement created.',
            'data'    => $this->formatAdmin($announcement->load('creator')),
        ], 201);
    }

    // =========================================================================
    // ADMIN  PUT /api/v1/admin/announcements/{id}
    // =========================================================================

    public function update(Request $request, int $id): JsonResponse
    {
        $announcement = Announcement::findOrFail($id);

        $validated = $request->validate([
            'title'        => ['sometimes', 'string', 'max:255'],
            'body'         => ['sometimes', 'string'],
            'category'     => ['sometimes', Rule::in(['health_alert', 'program', 'general'])],
            'banner_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ]);

        if ($request->hasFile('banner_image')) {
            if ($announcement->banner_image) {
                Storage::disk('public')->delete($announcement->banner_image);
            }
            $validated['banner_image'] = $request->file('banner_image')
                ->store('announcements/banners', 'public');
        }

        $announcement->update($validated);

        return response()->json([
            'message' => 'Announcement updated.',
            'data'    => $this->formatAdmin($announcement->fresh()->load('creator')),
        ]);
    }

    // =========================================================================
    // ADMIN  PATCH /api/v1/admin/announcements/{id}/publish
    // =========================================================================

    public function publish(Request $request, int $id): JsonResponse
    {
        $announcement    = Announcement::findOrFail($id);
        $shouldPublish   = $request->input('publish', true);

        $announcement->update([
            'status'       => $shouldPublish ? 'published' : 'draft',
            'published_at' => $shouldPublish ? ($announcement->published_at ?? now()) : null,
        ]);

        return response()->json([
            'message' => $shouldPublish
                ? 'Announcement published and visible to all residents.'
                : 'Announcement unpublished.',
            'data' => $this->formatAdmin($announcement->fresh()->load('creator')),
        ]);
    }

    // =========================================================================
    // ADMIN  DELETE /api/v1/admin/announcements/{id}
    // =========================================================================

    public function destroy(int $id): JsonResponse
    {
        $announcement = Announcement::findOrFail($id);

        if ($announcement->banner_image) {
            Storage::disk('public')->delete($announcement->banner_image);
        }

        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function formatMobile(Announcement $a): array
    {
        return [
            'id'           => $a->id,
            'title'        => $a->title,
            'body'         => $a->body,
            'category'     => $a->category,
            'banner_url'   => $a->banner_image
                ? Storage::disk('public')->url($a->banner_image)
                : null,
            'published_at' => optional($a->published_at)->toISOString(),
        ];
    }

    private function formatAdmin(Announcement $a): array
    {
        return [
            'id'           => $a->id,
            'title'        => $a->title,
            'body'         => $a->body,
            'category'     => $a->category,
            'banner_url'   => $a->banner_image
                ? Storage::disk('public')->url($a->banner_image)
                : null,
            'status'       => $a->status,
            'published_at' => optional($a->published_at)->toISOString(),
            'created_by'   => $a->creator
                ? $a->creator->first_name . ' ' . $a->creator->last_name
                : null,
            'updated_at'   => optional($a->updated_at)->toISOString(),
        ];
    }
}