<?php
// app/Http/Controllers/Api/EventController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    /**
     * MOBILE / RESIDENT
     * GET /api/v1/programs
     *
     * Returns published events with the logged-in user's registration
     * so the mobile event list can show "Registered" and queue number.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Event::query()
            ->published()
            ->withCount([
                'registrations as total_registered' => function ($query) {
                    $query->where('status', EventRegistration::STATUS_REGISTERED);
                },
            ])
            ->latest('published_at');

        if ($request->filled('search')) {
            $search = $request->query('search');

            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type') && $request->query('type') !== 'all') {
            $query->where('event_type', $request->query('type'));
        }

        if ($request->filled('priority') && $request->query('priority') !== 'all') {
            $query->where('priority', $request->query('priority'));
        }

        if ($request->filled('barangay') && $request->query('barangay') !== 'all') {
            $barangay = $request->query('barangay');

            $query->where(function ($q) use ($barangay) {
                // barangay_target is 'all', ONE name (legacy rows), or a
                // comma-separated list from the multi-select. The wrapped
                // ",list," LIKE ",name," check matches exact members of the
                // list without false-positives on partial names.
                $q->where('barangay_target', 'all')
                    ->orWhere('barangay_target', $barangay)
                    ->orWhereRaw(
                        "(',' || REPLACE(barangay_target, ', ', ',') || ',') LIKE ?",
                        ['%,' . $barangay . ',%']
                    );
            });
        }

        $events = $query->paginate((int) $request->query('per_page', 15));

        $eventIds = $events->getCollection()->pluck('id')->values();

        $registrationsByEvent = collect();

        if ($user && $eventIds->isNotEmpty()) {
            $registrationsByEvent = EventRegistration::query()
                ->where('user_id', $user->user_id)
                ->whereIn('event_id', $eventIds)
                ->where('status', EventRegistration::STATUS_REGISTERED)
                ->get()
                ->keyBy('event_id');
        }

        return response()->json([
            'data' => $events
                ->getCollection()
                ->map(function (Event $event) use ($registrationsByEvent) {
                    return $this->formatEvent(
                        $event,
                        $registrationsByEvent->get($event->id)
                    );
                })
                ->values(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    /**
     * MOBILE / RESIDENT
     * GET /api/v1/programs/{id}
     *
     * Returns one event with the logged-in user's event ticket.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $event = Event::published()
            ->withCount([
                'registrations as total_registered' => function ($query) {
                    $query->where('status', EventRegistration::STATUS_REGISTERED);
                },
            ])
            ->findOrFail($id);

        $registration = null;

        if ($user) {
            $registration = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('user_id', $user->user_id)
                ->latest('registered_at')
                ->first();
        }

        return response()->json([
            'data' => $this->formatEvent($event, $registration),
        ]);
    }

    /**
     * MOBILE / RESIDENT
     * GET /api/v1/my-event-registrations
     *
     * Used by the mobile Dashboard to show active event queue tickets.
     */
    public function myEventRegistrations(Request $request): JsonResponse
    {
        $user = $request->user();

        $registrations = EventRegistration::query()
            ->with([
                'event' => function ($query) {
                    $query->select([
                        'id',
                        'title',
                        'event_type',
                        'event_date',
                        'starts_at',
                        'location',
                        'is_published',
                    ]);
                },
            ])
            ->where('user_id', $user->user_id)
            ->where('status', EventRegistration::STATUS_REGISTERED)
            ->whereHas('event', function ($query) {
                $query->where('is_published', true);
            })
            ->latest('registered_at')
            ->get();

        return response()->json([
            'data' => $registrations->map(function (EventRegistration $registration) {
                $event = $registration->event;

                return [
                    'id' => $registration->id,
                    'event_id' => $registration->event_id,

                    'event_title' => $event?->title ?? 'Untitled Event',
                    'event_type' => $event?->event_type,
                    'event_date' => optional($event?->event_date ?? $event?->starts_at)->toISOString(),
                    'location' => $event?->location,

                    'status' => $registration->status,
                    'queue_number' => $registration->queue_number,
                    'registered_at' => optional($registration->registered_at)->toISOString(),
                ];
            })->values(),
        ]);
    }

    /**
     * ADMIN
     * GET /api/v1/admin/events
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = Event::query()
            ->with('creator:user_id,first_name,last_name')
            ->withCount([
                'registrations as total_registered' => function ($query) {
                    $query->where('status', EventRegistration::STATUS_REGISTERED);
                },
            ])
            ->latest();

        if ($request->filled('search')) {
            $search = $request->query('search');

            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            if ($request->query('status') === 'published') {
                $query->published();
            }

            if ($request->query('status') === 'draft') {
                $query->draft();
            }
        }

        if ($request->filled('type') && $request->query('type') !== 'all') {
            $query->where('event_type', $request->query('type'));
        }

        $events = $query->paginate((int) $request->query('per_page', 12));

        return response()->json([
            'data' => $events
                ->getCollection()
                ->map(fn (Event $event) => $this->formatEvent($event, null))
                ->values(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    /**
     * ADMIN
     * GET /api/v1/admin/events/{id}/registrants
     */
    public function registrants(Request $request, int $id): JsonResponse
    {
        $event = Event::query()
            ->withCount([
                'registrations as total_registered' => function ($query) {
                    $query->where('status', EventRegistration::STATUS_REGISTERED);
                },
            ])
            ->findOrFail($id);

        $registrants = EventRegistration::query()
            ->with([
                'user:user_id,first_name,last_name,email,mobile_number',
            ])
            ->where('event_id', $event->id)
            ->when($request->filled('status') && $request->query('status') !== 'all', function ($query) use ($request) {
                $query->where('status', $request->query('status'));
            })
            ->latest('registered_at')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_type' => $event->event_type,
                'event_date' => optional($event->event_date ?? $event->starts_at)->toISOString(),
                'location' => $event->location,
                'max_slots' => $event->max_slots,
                'slots_available' => $event->slots_available,
                'total_registered' => $event->total_registered,
            ],
            'data' => $registrants->getCollection()->map(function (EventRegistration $registration) {
                $user = $registration->user;

                return [
                    'id' => $registration->id,
                    'event_id' => $registration->event_id,
                    'user_id' => $registration->user_id,

                    'name' => $user
                        ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                        : 'Unknown resident',

                    'email' => $user?->email,
                    'mobile_number' => $user?->mobile_number,

                    'status' => $registration->status,
                    'queue_number' => $registration->queue_number,
                    'registered_at' => optional($registration->registered_at)->toISOString(),
                    'cancelled_at' => optional($registration->cancelled_at)->toISOString(),
                    'created_at' => optional($registration->created_at)->toISOString(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $registrants->currentPage(),
                'last_page' => $registrants->lastPage(),
                'per_page' => $registrants->perPage(),
                'total' => $registrants->total(),
            ],
        ]);
    }

    /**
     * ADMIN
     * POST /api/v1/admin/events
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        if ($request->hasFile('banner_image')) {
            $validated['banner_image'] = $request
                ->file('banner_image')
                ->store('events/banners', 'public');
        }

        $publish = (bool) ($validated['is_published'] ?? false);

        $maxSlots = $validated['max_slots'] ?? null;

        $event = Event::create([
            'title' => $validated['title'],
            'description' => $validated['description'],

            'event_type' => $validated['event_type'],
            'category' => $validated['category'] ?? null,

            'event_date' => $validated['event_date'] ?? null,
            'starts_at' => $validated['event_date'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,

            'location' => $validated['location'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,

            'barangay_target' => $validated['barangay_target'] ?? 'all',
            'target_audience' => $validated['target_audience'] ?? null,

            'tags' => $validated['tags'] ?? [],
            'services' => $this->cleanServices($validated['services'] ?? []),

            'max_slots' => $maxSlots,
            'slots_available' => $validated['slots_available'] ?? $maxSlots,

            'banner_image' => $validated['banner_image'] ?? null,

            'sms_summary' => $validated['sms_summary'] ?? null,

            'priority' => $validated['priority'] ?? 'normal',
            'visibility' => $validated['visibility'] ?? 'public',

            'is_published' => $publish,
            'published_at' => $publish ? now() : null,

            'created_by' => $request->user()?->user_id,
        ]);

        // Notify residents (push + stored notification) when an event/announcement
        // is published. Only the public title is sent — no sensitive data.
        $smsSent = 0;

        if ($publish) {
            try {
                $isEvent = ($validated['event_type'] ?? 'event') !== 'announcement';
                app(\App\Services\Notification\NotificationService::class)->notifyResidents(
                    $isEvent ? 'event' : 'announcement',
                    $isEvent ? 'New event posted by RHU' : 'New announcement from RHU',
                    (string) ($event->title ?? ($isEvent ? 'A new RHU event was posted.' : 'A new announcement was posted.')),
                    ['related_type' => $isEvent ? 'event' : 'announcement', 'related_id' => $event->id],
                    $isEvent ? '/events' : '/announcements'
                );
            } catch (\Throwable $e) {
                report($e);
            }

            // SMS blast to the post's target audience (barangay + facility
            // scoped; once per post). Never blocks or fails the publish.
            $smsSent = app(\App\Services\Notification\EventSmsService::class)
                ->sendPublishSms($event->fresh());
        }

        return response()->json([
            'message' => $publish
                ? ($smsSent > 0
                    ? "Post published and visible to residents. SMS sent to {$smsSent} recipient(s)."
                    : 'Post published and visible to residents.')
                : 'Draft saved successfully.',
            'data' => $this->formatEvent($event->fresh(), null),
            'sms_sent' => $smsSent,
        ], 201);
    }

    /**
     * ADMIN
     * PUT/PATCH/POST _method=PUT /api/v1/admin/events/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);

        $validated = $this->validatePayload($request, true);

        if ($request->hasFile('banner_image')) {
            if ($event->banner_image) {
                Storage::disk('public')->delete($event->banner_image);
            }

            $validated['banner_image'] = $request
                ->file('banner_image')
                ->store('events/banners', 'public');
        }

        if (array_key_exists('event_date', $validated)) {
            $validated['starts_at'] = $validated['event_date'];
        }

        if (array_key_exists('services', $validated)) {
            $validated['services'] = $this->cleanServices($validated['services'] ?? []);
        }

        if (array_key_exists('max_slots', $validated) && !array_key_exists('slots_available', $validated)) {
            $validated['slots_available'] = $validated['max_slots'];
        }

        $wasPublished = (bool) $event->is_published;

        if (array_key_exists('is_published', $validated)) {
            $publish = (bool) $validated['is_published'];
            $validated['published_at'] = $publish
                ? ($event->published_at ?? now())
                : null;
        }

        $event->update($validated);

        // Draft → published transition: same audience-scoped SMS blast as a
        // fresh publish (idempotent — sms_sent_at guards a re-blast).
        $smsSent = 0;

        if (!$wasPublished && (bool) $event->fresh()->is_published) {
            $smsSent = app(\App\Services\Notification\EventSmsService::class)
                ->sendPublishSms($event->fresh());
        }

        return response()->json([
            'message' => $smsSent > 0
                ? "Post updated successfully. SMS sent to {$smsSent} recipient(s)."
                : 'Post updated successfully.',
            'data' => $this->formatEvent($event->fresh(), null),
            'sms_sent' => $smsSent,
        ]);
    }

    /**
     * ADMIN
     * PATCH /api/v1/admin/events/{id}/publish
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'publish' => ['required', 'boolean'],
        ]);

        $event = Event::findOrFail($id);
        $publish = (bool) $request->boolean('publish');
        $wasPublished = (bool) $event->is_published;

        $event->update([
            'is_published' => $publish,
            'published_at' => $publish ? ($event->published_at ?? now()) : null,
        ]);

        // First-time publish from the card toggle blasts the same audience-
        // scoped SMS (sms_sent_at keeps re-publishes from texting twice).
        $smsSent = 0;

        if ($publish && !$wasPublished) {
            $smsSent = app(\App\Services\Notification\EventSmsService::class)
                ->sendPublishSms($event->fresh());
        }

        return response()->json([
            'message' => $publish
                ? ($smsSent > 0
                    ? "Post published and now visible on mobile. SMS sent to {$smsSent} recipient(s)."
                    : 'Post published and now visible on mobile.')
                : 'Post moved back to draft.',
            'data' => $this->formatEvent($event->fresh(), null),
            'sms_sent' => $smsSent,
        ]);
    }

    /**
     * ADMIN
     * DELETE /api/v1/admin/events/{id}
     */
    public function destroy(Request $request, int $id, AuditService $audit): JsonResponse
    {
        $event = Event::findOrFail($id);

        $reason = trim((string) (
            $request->input('reason')
            ?? $request->input('delete_reason')
            ?? 'Event deleted from RHU admin web.'
        ));

        $oldValues = $event->toArray();

        if (Schema::hasColumn('events', 'deleted_by')) {
            $event->deleted_by = $request->user()?->user_id;
        }

        if (Schema::hasColumn('events', 'delete_reason')) {
            $event->delete_reason = $reason;
        }

        $event->save();

        /*
         * Do not delete the banner image immediately.
         * If the event uses SoftDeletes, keeping the image allows future restore.
         * A cleanup command can remove unused files later.
         */
        $event->delete();

        $audit->log(
            request: $request,
            action: 'event.deleted',
            module: 'events',
            subject: $event,
            oldValues: $oldValues,
            newValues: [
                'deleted_at' => now()->toISOString(),
                'deleted_by' => $request->user()?->user_id,
                'delete_reason' => $reason,
            ],
            metadata: [
                'reason' => $reason,
                'delete_reason' => $reason,
                'event_type' => $oldValues['event_type'] ?? null,
                'was_published' => $oldValues['is_published'] ?? null,
            ],
            severity: 'warning',
            subjectLabel: $event->title
        );

        return response()->json([
            'message' => 'Post deleted successfully.',
        ]);
    }

    /**
     * MOBILE / RESIDENT
     * POST /api/v1/programs/{id}/register
     */
    public function register(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        return DB::transaction(function () use ($user, $id) {
            $event = Event::published()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($event->event_type === 'announcement') {
                return response()->json([
                    'message' => 'Announcements do not require registration.',
                ], 422);
            }

            $registration = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('user_id', $user->user_id)
                ->lockForUpdate()
                ->first();

            if ($registration && $registration->status === EventRegistration::STATUS_REGISTERED) {
                return response()->json([
                    'message' => 'You are already registered for this event.',
                    'data' => $this->formatRegistration($registration),
                ], 409);
            }

            if ($event->slots_available !== null && $event->slots_available <= 0) {
                return response()->json([
                    'message' => 'No available slots for this event.',
                ], 422);
            }

            if ($registration) {
                $registration->update([
                    'status' => EventRegistration::STATUS_REGISTERED,
                    'queue_number' => $registration->queue_number ?: $this->generateQueueNumber($event->id),
                    'registered_at' => now(),
                    'cancelled_at' => null,
                ]);

                $registration = $registration->fresh();
            } else {
                $registration = EventRegistration::create([
                    'event_id' => $event->id,
                    'user_id' => $user->user_id,
                    'status' => EventRegistration::STATUS_REGISTERED,
                    'queue_number' => $this->generateQueueNumber($event->id),
                    'registered_at' => now(),
                ]);
            }

            if ($event->slots_available !== null) {
                $event->decrement('slots_available');
            }

            return response()->json([
                'message' => 'Registered successfully.',
                'data' => $this->formatRegistration($registration),
            ], 201);
        });
    }

    /**
     * MOBILE / RESIDENT
     * DELETE /api/v1/programs/{id}/register
     */
    public function cancelRegistration(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        return DB::transaction(function () use ($user, $id) {
            $event = Event::published()
                ->lockForUpdate()
                ->findOrFail($id);

            $registration = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('user_id', $user->user_id)
                ->where('status', EventRegistration::STATUS_REGISTERED)
                ->lockForUpdate()
                ->firstOrFail();

            $registration->update([
                'status' => EventRegistration::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            if ($event->slots_available !== null) {
                $event->increment('slots_available');
            }

            return response()->json([
                'message' => 'Registration cancelled successfully.',
            ]);
        });
    }

    /**
     * Trim + drop blank entries. The admin form sends one empty services[]
     * marker when the user clears every service, so an explicit "no services"
     * update reaches the model as [] instead of the key being absent.
     */
    private function cleanServices(array $services): array
    {
        return array_values(array_filter(
            array_map(fn ($service) => trim((string) $service), $services),
            fn (string $service) => $service !== ''
        ));
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => [$required, 'string'],

            'event_type' => [$required, Rule::in(['event', 'program', 'announcement'])],

            'category' => ['nullable', 'string', 'max:100'],

            'event_date' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:event_date'],

            'location' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            // Multi-select barangay targeting: 'all' or a comma-separated
            // list of barangay names (column widened to TEXT).
            'barangay_target' => ['nullable', 'string', 'max:2000'],
            'target_audience' => ['nullable', 'string', 'max:255'],

            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],

            // "RHU Service Offered" classification — one event may belong to
            // multiple RHU program services (additive field).
            'services' => ['nullable', 'array', 'max:60'],
            'services.*' => ['string', 'max:120'],

            'max_slots' => ['nullable', 'integer', 'min:1'],
            'slots_available' => ['nullable', 'integer', 'min:0'],

            'banner_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

            'sms_summary' => ['nullable', 'string', 'max:160'],

            'priority' => ['nullable', Rule::in(['normal', 'high', 'urgent'])],
            'visibility' => ['nullable', Rule::in(['public', 'rhu1', 'rhu2'])],

            'is_published' => ['nullable', 'boolean'],
        ]);
    }

    private function formatEvent(Event $event, ?EventRegistration $registration = null): array
    {
        return [
            'id' => $event->id,

            'title' => $event->title,
            'description' => $event->description,

            'event_type' => $event->event_type ?? 'event',
            'content_type' => $event->event_type ?? 'event',

            'category' => $event->category,

            'event_date' => optional($event->event_date ?? $event->starts_at)->toISOString(),
            'starts_at' => optional($event->event_date ?? $event->starts_at)->toISOString(),
            'ends_at' => optional($event->ends_at)->toISOString(),

            'location' => $event->location,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,

            'barangay_target' => $event->barangay_target ?? 'all',
            'target_audience' => $event->target_audience,

            'tags' => $event->tags ?? [],
            'services' => $event->services ?? [],

            'max_slots' => $event->max_slots,
            'slots_available' => $event->slots_available,

            'banner_image' => $event->banner_image,
            'banner_url' => $event->banner_url,
            'image_url' => $event->banner_url,

            'sms_summary' => $event->sms_summary,

            'priority' => $event->priority ?? 'normal',
            'visibility' => $event->visibility ?? 'public',

            'is_published' => (bool) $event->is_published,
            'published_at' => optional($event->published_at)->toISOString(),

            'total_registered' => (int) ($event->total_registered ?? 0),

            'is_registered' => $registration?->status === EventRegistration::STATUS_REGISTERED,

            'registration' => $registration ? $this->formatRegistration($registration) : null,

            'created_by' => $event->creator
                ? trim($event->creator->first_name . ' ' . $event->creator->last_name)
                : null,

            'created_at' => optional($event->created_at)->toISOString(),
            'updated_at' => optional($event->updated_at)->toISOString(),
        ];
    }

    private function formatRegistration(EventRegistration $registration): array
    {
        return [
            'id' => $registration->id,
            'event_id' => $registration->event_id,
            'user_id' => $registration->user_id,
            'status' => $registration->status,
            'queue_number' => $registration->queue_number,
            'registered_at' => optional($registration->registered_at)->toISOString(),
            'cancelled_at' => optional($registration->cancelled_at)->toISOString(),
            'created_at' => optional($registration->created_at)->toISOString(),
            'updated_at' => optional($registration->updated_at)->toISOString(),
        ];
    }

    private function generateQueueNumber(int $eventId): string
    {
        do {
            $queueNumber = 'EVT-' . $eventId . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (
            EventRegistration::where('queue_number', $queueNumber)->exists()
        );

        return $queueNumber;
    }
}