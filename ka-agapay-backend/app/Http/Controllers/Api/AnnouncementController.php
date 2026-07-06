<?php
// app/Http/Controllers/Api/AnnouncementController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\Audit\AuditService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    private string $table = 'announcements';

    /**
     * MOBILE/PUBLIC
     * GET /api/v1/announcements
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable($this->table), 404, 'Announcements table not found.');

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->baseQuery()
            ->when(Schema::hasColumn($this->table, 'status'), fn (Builder $q) => $q->where('status', 'published'))
            ->when(Schema::hasColumn($this->table, 'published_at'), fn (Builder $q) => $q->whereNotNull('published_at'));

        if ($request->filled('search')) {
            $this->applySearch($query, (string) $request->query('search'));
        }

        if ($request->filled('category') && Schema::hasColumn($this->table, 'category')) {
            $query->where('category', $request->query('category'));
        }

        $sortColumn = Schema::hasColumn($this->table, 'published_at') ? 'published_at' : 'created_at';

        $items = $query
            ->orderByDesc($sortColumn)
            ->paginate($request->integer('per_page', 20));

        $items->getCollection()->transform(fn ($row) => $this->format($row));

        return response()->json($items);
    }

    /**
     * ADMIN
     * GET /api/v1/admin/announcements
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorizeCms($request);

        abort_unless(Schema::hasTable($this->table), 404, 'Announcements table not found.');

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'category' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->baseQuery();

        if ($request->filled('search')) {
            $this->applySearch($query, (string) $request->query('search'));
        }

        if ($request->filled('status') && Schema::hasColumn($this->table, 'status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('category') && Schema::hasColumn($this->table, 'category')) {
            $query->where('category', $request->query('category'));
        }

        $sortColumn = Schema::hasColumn($this->table, 'created_at') ? 'created_at' : 'id';

        $items = $query
            ->orderByDesc($sortColumn)
            ->paginate($request->integer('per_page', 20));

        $items->getCollection()->transform(fn ($row) => $this->format($row));

        return response()->json($items);
    }

    /**
     * PUBLIC
     * GET /api/v1/announcements/{id}
     */
    public function show(int $id): JsonResponse
    {
        abort_unless(Schema::hasTable($this->table), 404, 'Announcements table not found.');

        $row = $this->baseQuery()
            ->where('id', $id)
            ->first();

        abort_unless($row, 404, 'Announcement not found.');

        return response()->json([
            'data' => $this->format($row),
        ]);
    }

    /**
     * ADMIN
     * POST /api/v1/admin/announcements
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeCms($request);

        $validated = $this->validatePayload($request);

        $data = $this->payloadToDb($validated, $request);

        if (Schema::hasColumn($this->table, 'created_by')) {
            $data['created_by'] = $request->user()?->user_id ?? $request->user()?->id;
        }

        if (($data['status'] ?? 'draft') === 'published' && Schema::hasColumn($this->table, 'published_at')) {
            $data['published_at'] = now();
        }

        if (Schema::hasColumn($this->table, 'created_at')) {
            $data['created_at'] = now();
        }

        if (Schema::hasColumn($this->table, 'updated_at')) {
            $data['updated_at'] = now();
        }

        $id = DB::table($this->table)->insertGetId($this->onlyExistingColumns($data));

        $row = $this->baseQuery()->where('id', $id)->first();

        // Notify residents (push + stored notification) when content is published.
        // Only the public title is sent — no sensitive data in the notification.
        if (($data['status'] ?? 'draft') === 'published') {
            try {
                $isEvent = str_contains(strtolower($this->table), 'event');
                app(\App\Services\Notification\NotificationService::class)->notifyResidents(
                    $isEvent ? 'event' : 'announcement',
                    $isEvent ? 'New event posted by RHU' : 'New announcement from RHU',
                    (string) ($row->title ?? ($isEvent ? 'A new RHU event was posted.' : 'A new announcement was posted.')),
                    ['related_type' => $isEvent ? 'event' : 'announcement', 'related_id' => $id],
                    $isEvent ? '/events' : '/announcements'
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'message' => 'Announcement created.',
            'data' => $this->format($row),
        ], 201);
    }

    /**
     * ADMIN
     * PUT/PATCH/POST /api/v1/admin/announcements/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeCms($request);

        $validated = $this->validatePayload($request, true);

        $row = DB::table($this->table)->where('id', $id)->first();
        abort_unless($row, 404, 'Announcement not found.');

        $data = $this->payloadToDb($validated, $request, true);

        if (array_key_exists('status', $data) && Schema::hasColumn($this->table, 'published_at')) {
            if ($data['status'] === 'published' && empty($row->published_at)) {
                $data['published_at'] = now();
            }

            if ($data['status'] === 'draft') {
                $data['published_at'] = null;
            }
        }

        if (Schema::hasColumn($this->table, 'updated_at')) {
            $data['updated_at'] = now();
        }

        if (!empty($data)) {
            DB::table($this->table)
                ->where('id', $id)
                ->update($this->onlyExistingColumns($data));
        }

        $fresh = $this->baseQuery()->where('id', $id)->first();

        return response()->json([
            'message' => 'Announcement updated.',
            'data' => $this->format($fresh),
        ]);
    }

    /**
     * ADMIN
     * PATCH /api/v1/admin/announcements/{id}/publish
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $this->authorizeCms($request);

        $row = DB::table($this->table)->where('id', $id)->first();
        abort_unless($row, 404, 'Announcement not found.');

        $publish = $request->boolean('is_published', $request->boolean('publish', true));

        $data = [];

        if (Schema::hasColumn($this->table, 'status')) {
            $data['status'] = $publish ? 'published' : 'draft';
        }

        if (Schema::hasColumn($this->table, 'published_at')) {
            $data['published_at'] = $publish ? now() : null;
        }

        if (Schema::hasColumn($this->table, 'archived_at') && $publish) {
            $data['archived_at'] = null;
        }

        if (Schema::hasColumn($this->table, 'updated_at')) {
            $data['updated_at'] = now();
        }

        DB::table($this->table)->where('id', $id)->update($data);

        $fresh = $this->baseQuery()->where('id', $id)->first();

        return response()->json([
            'message' => $publish ? 'Announcement published.' : 'Announcement moved to draft.',
            'data' => $this->format($fresh),
        ]);
    }

    /**
     * ADMIN
     * PATCH /api/v1/admin/announcements/{id}/archive
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        $this->authorizeCms($request);

        $row = DB::table($this->table)->where('id', $id)->first();
        abort_unless($row, 404, 'Announcement not found.');

        $data = [];

        if (Schema::hasColumn($this->table, 'status')) {
            $data['status'] = 'archived';
        }

        if (Schema::hasColumn($this->table, 'archived_at')) {
            $data['archived_at'] = now();
        }

        if (Schema::hasColumn($this->table, 'archived_by')) {
            $data['archived_by'] = $request->user()?->user_id ?? $request->user()?->id;
        }

        if (Schema::hasColumn($this->table, 'updated_at')) {
            $data['updated_at'] = now();
        }

        DB::table($this->table)->where('id', $id)->update($data);

        $fresh = $this->baseQuery()->where('id', $id)->first();

        return response()->json([
            'message' => 'Announcement archived.',
            'data' => $this->format($fresh),
        ]);
    }

    /**
     * ADMIN
     * DELETE /api/v1/admin/announcements/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeCms($request);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $reason = trim((string) ($validated['reason'] ?? '')) ?: 'Announcement archived by staff.';

        // Archive (soft-delete) via the model so it lands in Delete & Archive
        // History and can be restored — matching the Inventory/Event pattern.
        $announcement = Announcement::findOrFail($id);
        $snapshot = array_merge($announcement->attributesToArray(), ['id' => $announcement->getKey()]);
        $label = (string) ($announcement->title ?? ('Announcement #' . $announcement->getKey()));

        DB::transaction(function () use ($request, $announcement, $reason) {
            $table = $announcement->getTable();
            $actorId = $request->user()?->user_id ?? $request->user()?->id;

            $updates = [];
            if (Schema::hasColumn($table, 'deleted_by')) {
                $updates['deleted_by'] = $actorId;
            }
            if (Schema::hasColumn($table, 'delete_reason')) {
                $updates['delete_reason'] = $reason;
            }
            if (!empty($updates)) {
                $announcement->forceFill($updates)->save();
            }

            $announcement->delete(); // soft delete (SoftDeletes) — recoverable
        });

        // Attribution + reason live in the audit log (single source of truth),
        // surfaced by the Delete & Archive History recycle bin.
        app(AuditService::class)->log(
            $request,
            'announcement.deleted',
            'announcements',
            $announcement,
            $snapshot,
            [],
            [
                'reason' => $reason,
                'delete_reason' => $reason,
                'archive_reason' => $reason,
                'restore_id' => $announcement->getKey(),
            ],
            'warning',
            $label
        );

        return response()->json([
            'message' => 'Announcement archived. It can be restored from Delete & Archive History within 30 days.',
        ]);
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'body' => [$required, 'string'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', Rule::in(['health_alert', 'program', 'general'])],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'banner_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
    }

    private function payloadToDb(array $validated, Request $request, bool $partial = false): array
    {
        $data = [];

        foreach (['title', 'body', 'category', 'status'] as $key) {
            if (array_key_exists($key, $validated)) {
                $data[$key] = $validated[$key];
            }
        }

        if (!$partial) {
            $data['category'] = $data['category'] ?? 'general';
            $data['status'] = $data['status'] ?? 'draft';
        }

        if ($request->hasFile('banner_image')) {
            $data['banner_path'] = $request
                ->file('banner_image')
                ->store('announcements/banners', 'public');
        }

        return $data;
    }

    private function baseQuery(): Builder
    {
        $query = DB::table($this->table);

        if (Schema::hasColumn($this->table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    private function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%");

            if (Schema::hasColumn($this->table, 'category')) {
                $q->orWhere('category', 'like', "%{$search}%");
            }
        });
    }

    private function onlyExistingColumns(array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $key) => Schema::hasColumn($this->table, (string) $key) && $value !== null)
            ->all();
    }

    private function format(object $row): array
    {
        $bannerPath = $row->banner_path ?? null;

        return [
            'id' => (int) $row->id,
            'title' => (string) ($row->title ?? ''),
            'body' => (string) ($row->body ?? ''),
            'description' => (string) ($row->body ?? ''),
            'category' => (string) ($row->category ?? 'general'),
            'status' => (string) ($row->status ?? 'draft'),
            'banner_path' => $bannerPath,
            'banner_url' => $bannerPath ? Storage::disk('public')->url($bannerPath) : null,
            'image_url' => $bannerPath ? Storage::disk('public')->url($bannerPath) : null,
            'published_at' => $row->published_at ?? null,
            'archived_at' => $row->archived_at ?? null,
            'archived_by' => $row->archived_by ?? null,
            'created_by' => $row->created_by ?? null,
            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    private function authorizeCms(Request $request): void
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');

        $role = strtolower((string) ($user->role?->name ?? $user->role?->role_name ?? $user->role?->slug ?? ''));

        $allowed = [
            'super_admin',
            'superadmin',
            'admin',
            'rhu_admin',
            'staff_admin',
            'staff',
            'mho',
            'doctor',
            'nurse',
            'midwife',
            'bhw',
        ];

        abort_unless(in_array($role, $allowed, true), 403, 'You are not allowed to manage announcements.');
    }
}
