<?php
// app/Http/Controllers/Api/FollowUpReminderController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Consultation;
use App\Models\FollowUpReminder;
use App\Models\User;
use App\Services\Notification\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class FollowUpReminderController extends Controller
{
    private const STAFF_ROLES = [
        'admin', 'staff', 'staff_admin', 'rhu_admin', 'mho',
        'super_admin', 'doctor', 'nurse', 'midwife', 'bhw',
    ];

    public function __construct(private readonly SmsService $sms)
    {
    }

    /**
     * GET /api/v1/follow-up-reminders
     * RHU staff see only their RHU; super_admin/mho see all.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = FollowUpReminder::query()
            ->with(['user', 'rhu', 'createdBy', 'consultation'])
            ->latest('id');

        if ($user && !$user->isGlobalRhuScope()) {
            $query->where('rhu_id', (int) ($user->effectiveRhuId() ?? 0));
        } elseif ($request->filled('rhu_id')) {
            $query->where('rhu_id', $request->integer('rhu_id'));
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('urgency') && $request->query('urgency') !== 'all') {
            $query->where('urgency', (string) $request->query('urgency'));
        }

        return response()->json(
            $query->paginate($request->integer('per_page', 50))
        );
    }

    /**
     * POST /api/v1/follow-up-reminders
     * Saved from the SOAP follow-up section. Creates/updates one reminder per
     * consultation and (optionally) sends an SMS alert.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'consultation_id' => ['nullable', 'integer'],
            'appointment_id' => ['nullable', 'integer'],
            'needs_follow_up' => ['nullable', 'boolean'],
            'follow_up_date' => ['nullable', 'date'],
            'follow_up_time' => ['nullable', 'string', 'max:10'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'urgency' => ['nullable', Rule::in(FollowUpReminder::URGENCY_LEVELS)],
            'sms_enabled' => ['nullable', 'boolean'],
        ]);

        $consultation = !empty($validated['consultation_id'])
            ? Consultation::with(['resident.residentProfile', 'appointment'])->find($validated['consultation_id'])
            : null;

        $user = $consultation?->resident;
        $profile = $user?->residentProfile;

        $mobile = $user?->mobile_number
            ?? $profile?->mobile_number
            ?? $profile?->contact_number;

        $rhuId = ($consultation?->appointment?->rhu_id ?? null) ?: ($profile?->barangay_id ?? null);
        $appointmentId = $validated['appointment_id'] ?? $consultation?->appointment_id;

        $followUpAt = $this->combineDateTime(
            $validated['follow_up_date'] ?? null,
            $validated['follow_up_time'] ?? null
        );

        // "Follow-up needed = No" → cancel any existing reminder, do not create.
        if (array_key_exists('needs_follow_up', $validated) && $validated['needs_follow_up'] === false) {
            if ($consultation) {
                FollowUpReminder::where('consultation_id', $consultation->id)
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->update(['status' => 'cancelled']);
            }

            return response()->json([
                'message' => 'No follow-up required. Any pending reminder was cancelled.',
                'data' => null,
            ]);
        }

        $payload = [
            'appointment_id' => $appointmentId,
            'user_id' => $consultation?->user_id,
            'resident_profile_id' => $profile?->id,
            'rhu_id' => $rhuId,
            'created_by' => $this->userId($request),
            'patient_name' => $user?->full_name,
            'mobile_number' => $mobile,
            'follow_up_at' => $followUpAt,
            'follow_up_date' => $validated['follow_up_date'] ?? null,
            'follow_up_time' => $validated['follow_up_time'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'instructions' => $validated['instructions'] ?? null,
            'urgency' => $validated['urgency'] ?? 'routine',
            'status' => $followUpAt ? 'scheduled' : 'pending',
        ];

        $reminder = $consultation
            ? FollowUpReminder::updateOrCreate(
                ['consultation_id' => $consultation->id],
                $payload
            )
            : FollowUpReminder::create([...$payload, 'consultation_id' => null]);

        // SMS alert — non-fatal, deduped, only when enabled + a mobile exists.
        $smsEnabled = $validated['sms_enabled'] ?? true;
        if ($smsEnabled) {
            $this->maybeSendSms($reminder, $mobile, $rhuId);
        }

        return response()->json([
            'message' => 'Follow-up reminder saved.',
            'data' => $reminder->fresh(['user', 'rhu', 'createdBy']),
        ], 201);
    }

    /**
     * PATCH /api/v1/follow-up-reminders/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$this->isStaff($user)) {
            return response()->json(['message' => 'Only RHU staff can update reminders.'], 403);
        }

        $reminder = FollowUpReminder::findOrFail($id);

        if (
            !$user->isGlobalRhuScope()
            && (int) $reminder->rhu_id !== (int) ($user->effectiveRhuId() ?? 0)
        ) {
            return response()->json(['message' => 'You can only update reminders for your assigned RHU.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(FollowUpReminder::STATUSES)],
        ]);

        $reminder->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Reminder status updated.',
            'data' => $reminder->fresh(['user', 'rhu', 'createdBy']),
        ]);
    }

    private function maybeSendSms(FollowUpReminder $reminder, ?string $mobile, ?int $rhuId): void
    {
        $normalized = $this->normalizePhone($mobile);

        if (!$normalized) {
            $reminder->update(['sms_status' => 'no_mobile']);
            return;
        }

        // Avoid duplicate spam: only send once unless it has not been sent yet.
        if ($reminder->sms_sent_at) {
            return;
        }

        try {
            $message = $this->buildSmsMessage($reminder, $rhuId);

            $log = $this->sms->send($normalized, $message, 'followup_reminder', $reminder->user_id);

            $reminder->update([
                'sms_status' => $log->status,
                'sms_sent_at' => $log->status === 'sent' ? now() : null,
                'sms_error' => $log->status === 'failed'
                    ? ($log->error_message ?? 'SMS failed')
                    : null,
            ]);
        } catch (\Throwable $e) {
            // Never break the SOAP save because of SMS.
            $reminder->update([
                'sms_status' => 'failed',
                'sms_error' => $e->getMessage(),
            ]);
        }
    }

    private function buildSmsMessage(FollowUpReminder $reminder, ?int $rhuId): string
    {
        $name = $reminder->patient_name ?: 'Resident';

        $rhuName = $rhuId
            ? (Barangay::query()->where('barangay_id', $rhuId)->value('name')
                ?? Barangay::query()->where('barangay_id', $rhuId)->value('barangay_name'))
            : null;

        $when = trim(
            ($reminder->follow_up_date ? Carbon::parse($reminder->follow_up_date)->format('M d, Y') : '')
            . ' ' . ($reminder->follow_up_time ? substr((string) $reminder->follow_up_time, 0, 5) : '')
        );

        $parts = [
            "Ka-Agapay RHU: Hi {$name},",
            $when ? "you have a health follow-up on {$when}" : 'you have a health follow-up',
            $rhuName ? "at {$rhuName} RHU." : 'at the RHU.',
        ];

        if ($reminder->instructions) {
            $parts[] = trim((string) $reminder->instructions);
        }

        $parts[] = 'Please visit the RHU on schedule.';

        return trim(implode(' ', array_filter($parts)));
    }

    private function combineDateTime(?string $date, ?string $time): ?Carbon
    {
        if (!$date) {
            return null;
        }

        try {
            $t = $time ? substr($time, 0, 5) : '09:00';
            return Carbon::parse("{$date} {$t}");
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizePhone(?string $number): ?string
    {
        if (!$number) {
            return null;
        }

        $n = preg_replace('/[^\d+]/', '', trim($number)) ?? '';

        if (preg_match('/^09\d{9}$/', $n)) {
            return $n; // Semaphore accepts 09XXXXXXXXX
        }

        if (preg_match('/^\+?639\d{9}$/', $n)) {
            return ltrim($n, '+');
        }

        return $n !== '' ? $n : null;
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
