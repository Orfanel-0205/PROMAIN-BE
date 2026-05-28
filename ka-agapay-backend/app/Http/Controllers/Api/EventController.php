<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(): JsonResponse
    {
        $events = Event::where('status', '!=', 'cancelled')->latest('event_date')->paginate(15);
        return response()->json($events);
    }

    public function show(int $id): JsonResponse
    {
        $event = Event::withCount('registrations')->findOrFail($id);
        return response()->json(['event' => $event]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'location'    => 'nullable|string|max:255',
            'event_date'  => 'required|date',
            'max_slots'   => 'nullable|integer|min:1',
        ]);

        $event = Event::create([
            ...$validated,
            'created_by' => $request->user()->user_id,
        ]);

        return response()->json(['message' => 'Event created.', 'event' => $event], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);

        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'location'    => 'nullable|string|max:255',
            'event_date'  => 'sometimes|date',
            'max_slots'   => 'nullable|integer|min:1',
            'status'      => 'sometimes|in:upcoming,ongoing,completed,cancelled',
        ]);

        $event->update($validated);

        return response()->json(['message' => 'Event updated.', 'event' => $event]);
    }
    // RESIDENT==> REGISTER FOR THE EVENT
    public function register(Request $request, int $event): JsonResponse
    {
        $eventModel = Event::findOrFail($event);

        if ($eventModel->status === 'cancelled' || $eventModel->status === 'completed') {
            return response()->json(['message' => 'Event is no longer open for registration.'], 422);
        }

        if ($eventModel->max_slots) {
            $count = $eventModel->registrations()->where('status', 'registered')->count();
            if ($count >= $eventModel->max_slots) {
                return response()->json(['message' => 'No slots available.'], 422);
            }
        }

        $existing = EventRegistration::where('event_id', $event)
            ->where('user_id', $request->user()->user_id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Already registered for this event.'], 422);
        }

        $registration = EventRegistration::create([
            'event_id' => $event,
            'user_id'  => $request->user()->user_id,
            'status'   => 'registered',
        ]);

        return response()->json(['message' => 'Registered successfully.', 'registration' => $registration], 201);
    }
}