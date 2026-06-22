<?php
// app/Http/Controllers/Api/FeedbackController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use App\Models\ServiceFeedback;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    private const STAFF_ROLES = [
        'admin',
        'staff',
        'staff_admin',
        'rhu_admin',
        'mho',
        'super_admin',
        'doctor',
        'nurse',
        'midwife',
        'bhw',
    ];

    /**
     * GET /api/v1/feedback
     *
     * - Resident: only their own feedback.
     * - RHU staff/admin: only their assigned RHU.
     * - super_admin / mho: all (optionally filtered by rhu_id).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ServiceFeedback::query()
            ->with(['user', 'rhu', 'respondedBy'])
            ->latest('id');

        if ($this->isStaff($user)) {
            if (!$user->isGlobalRhuScope()) {
                // Hard-lock to their RHU. 0 guarantees no cross-RHU leak when
                // the account has no assignment yet.
                $query->where('rhu_id', (int) ($user->effectiveRhuId() ?? 0));
            } elseif ($request->filled('rhu_id')) {
                $query->where('rhu_id', $request->integer('rhu_id'));
            }
        } else {
            $query->where('user_id', $this->userId($request));
        }

        if ($request->filled('service_type')) {
            $query->where('service_type', (string) $request->query('service_type'));
        }

        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->query('rating'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return response()->json(
            $query->paginate($request->integer('per_page', 20))
        );
    }

    /**
     * POST /api/v1/feedback
     * Resident submits feedback for a completed service.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // service_type defaults to health_followup; legacy values still allowed.
            'service_type' => ['nullable', Rule::in(ServiceFeedback::SERVICE_TYPES)],
            'followup_type' => ['nullable', 'string', 'max:40'],

            // rating is optional now — auto-mapped from condition_status when absent.
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],

            // Health follow-up (medical) fields
            'condition_status' => ['nullable', Rule::in(ServiceFeedback::CONDITION_STATUSES)],
            'symptoms_present' => ['nullable', 'boolean'],
            'medication_taken' => ['nullable', Rule::in(ServiceFeedback::MEDICATION_TAKEN)],
            'side_effects' => ['nullable', 'boolean'],
            'side_effects_description' => ['nullable', 'string', 'max:2000'],
            'patient_message' => ['nullable', 'string', 'max:2000'],
            'needs_follow_up' => ['nullable', 'boolean'],
            'urgency_level' => ['nullable', Rule::in(ServiceFeedback::URGENCY_LEVELS)],

            'appointment_id' => ['nullable', 'integer'],
            'consultation_id' => ['nullable', 'integer'],
            'queue_ticket_id' => ['nullable', 'integer'],
            'prescription_id' => ['nullable', 'integer'],
            'laboratory_result_id' => ['nullable', 'integer'],
        ]);

        $userId = $this->userId($request);
        $serviceType = $validated['service_type'] ?? 'health_followup';

        $appointmentId = $validated['appointment_id'] ?? null;
        $consultationId = $validated['consultation_id'] ?? null;
        $rhuId = null;

        // Derive appointment_id from the consultation when not provided.
        if ($consultationId) {
            $consultation = Consultation::find($consultationId);

            if (!$consultation) {
                return response()->json([
                    'message' => 'The referenced consultation could not be found.',
                    'errors' => ['consultation_id' => ['Consultation not found.']],
                ], 422);
            }

            $appointmentId = $appointmentId ?: $consultation->appointment_id;
        }

        // Derive RHU from the appointment (source of truth).
        if ($appointmentId && Schema::hasColumn('appointments', 'rhu_id')) {
            $rhuId = Appointment::where('id', $appointmentId)->value('rhu_id');
        }

        // Fallbacks for RHU: queue ticket, then resident's barangay.
        if (!$rhuId && !empty($validated['queue_ticket_id'])) {
            $rhuId = QueueTicket::where('id', $validated['queue_ticket_id'])->value('rhu_id');
        }

        if (!$rhuId && Schema::hasTable('resident_profiles')) {
            $rhuId = ResidentProfile::where('user_id', $userId)->value('barangay_id');
        }

        // Prevent duplicate feedback for the same consultation + service type.
        if ($consultationId) {
            $alreadySubmitted = ServiceFeedback::query()
                ->where('user_id', $userId)
                ->where('consultation_id', $consultationId)
                ->where('service_type', $serviceType)
                ->exists();

            if ($alreadySubmitted) {
                return response()->json([
                    'message' => 'You have already submitted feedback for this service.',
                ], 422);
            }
        }

        // The DB still requires a rating column. Auto-map it from the patient's
        // reported condition when the app does not send an explicit rating.
        $conditionStatus = $validated['condition_status'] ?? null;
        $rating = $validated['rating'] ?? $this->ratingFromCondition($conditionStatus);

        // Mirror the medical message into the legacy comment column when the
        // comment is empty, so old screens keep showing something useful.
        $patientMessage = $validated['patient_message'] ?? null;
        $comment = $validated['comment'] ?? null;

        if (!$comment && $patientMessage) {
            $comment = $patientMessage;
        }

        $feedback = ServiceFeedback::create([
            'user_id' => $userId,
            'rhu_id' => $rhuId ?: null,
            'appointment_id' => $appointmentId ?: null,
            'consultation_id' => $consultationId ?: null,
            'queue_ticket_id' => $validated['queue_ticket_id'] ?? null,
            'prescription_id' => $validated['prescription_id'] ?? null,
            'laboratory_result_id' => $validated['laboratory_result_id'] ?? null,
            'service_type' => $serviceType,
            'rating' => $rating,
            'comment' => $comment,
            'status' => 'submitted',

            'followup_type' => $validated['followup_type'] ?? 'medical_followup',
            'condition_status' => $conditionStatus,
            'symptoms_present' => $validated['symptoms_present'] ?? null,
            'medication_taken' => $validated['medication_taken'] ?? null,
            'side_effects' => $validated['side_effects'] ?? null,
            'side_effects_description' => $validated['side_effects_description'] ?? null,
            'patient_message' => $patientMessage,
            'needs_follow_up' => $validated['needs_follow_up'] ?? false,
            'urgency_level' => $validated['urgency_level'] ?? 'routine',
        ]);

        return response()->json([
            'message' => 'Thank you. Your health follow-up has been submitted to the RHU.',
            'data' => $feedback->fresh(['user', 'rhu', 'respondedBy']),
        ], 201);
    }

    private function ratingFromCondition(?string $condition): int
    {
        return match ($condition) {
            'recovered' => 5,
            'improved' => 4,
            'worse' => 1,
            default => 3, // 'same' or unspecified
        };
    }

    /**
     * GET /api/v1/feedback/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $feedback = ServiceFeedback::with(['user', 'rhu', 'respondedBy'])->findOrFail($id);

        $user = $request->user();
        $isOwner = (int) $feedback->user_id === (int) $this->userId($request);

        $canView = $isOwner
            || $user->isGlobalRhuScope()
            || (
                $this->isStaff($user)
                && (int) $feedback->rhu_id === (int) ($user->effectiveRhuId() ?? 0)
            );

        if (!$canView) {
            return response()->json([
                'message' => 'You are not allowed to view this feedback.',
            ], 403);
        }

        return response()->json([
            'data' => $feedback,
        ]);
    }

    /**
     * PATCH /api/v1/feedback/{id}/respond
     * Staff/admin responds to feedback (RHU-scoped).
     */
    public function respond(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$this->isStaff($user)) {
            return response()->json([
                'message' => 'Only RHU staff can respond to feedback.',
            ], 403);
        }

        $feedback = ServiceFeedback::findOrFail($id);

        if (
            !$user->isGlobalRhuScope()
            && (int) $feedback->rhu_id !== (int) ($user->effectiveRhuId() ?? 0)
        ) {
            return response()->json([
                'message' => 'You can only respond to feedback for your assigned RHU.',
            ], 403);
        }

        $validated = $request->validate([
            'admin_response' => ['required', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(ServiceFeedback::STATUSES)],
        ]);

        $feedback->update([
            'admin_response' => $validated['admin_response'],
            'responded_by' => $this->userId($request),
            'responded_at' => now(),
            'reviewed_at' => now(),
            'status' => $validated['status'] ?? 'responded',
        ]);

        return response()->json([
            'message' => 'RHU response saved.',
            'data' => $feedback->fresh(['user', 'rhu', 'respondedBy']),
        ]);
    }

    private function isStaff(?User $user): bool
    {
        return $user ? $user->hasAnyRole(self::STAFF_ROLES) : false;
    }

    private function userId(Request $request): int
    {
        $user = $request->user();

        return (int) ($user->user_id ?? $user->id ?? 0);
    }
}
