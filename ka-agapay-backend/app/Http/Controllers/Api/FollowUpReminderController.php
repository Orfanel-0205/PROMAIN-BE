<?php
// app/Http/Controllers/Api/FollowUpReminderController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\FollowUpReminder;
use App\Models\User;
use App\Services\Notification\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
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
            'force_sms' => ['nullable', 'boolean'],
            'mobile_number' => ['nullable', 'string', 'max:40'],
        ]);

        $consultation = !empty($validated['consultation_id'])
            ? Consultation::with(['resident.residentProfile', 'appointment'])->find($validated['consultation_id'])
            : null;

        $user = $consultation?->resident;
        $profile = $user?->residentProfile;

        // Mobile number priority:
        // 1) editable form field, 2) user, 3) profile mobile/contact/phone.
        $mobile = $this->firstFilled([
            $validated['mobile_number'] ?? null,
            $user?->mobile_number,
            $profile?->mobile_number,
            $profile?->contact_number,
            $profile?->phone_number,
        ]);

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

        // Capture the existing reminder BEFORE updating so we can tell whether the
        // patient-facing details changed (which should re-trigger the SMS).
        $existing = $consultation
            ? FollowUpReminder::where('consultation_id', $consultation->id)->first()
            : null;

        $contentChanged = $this->followUpContentChanged($existing, $payload);

        $reminder = $consultation
            ? FollowUpReminder::updateOrCreate(
                ['consultation_id' => $consultation->id],
                $payload
            )
            : FollowUpReminder::create([...$payload, 'consultation_id' => null]);

        // SMS alert — non-fatal, only when enabled + a mobile exists.
        // Resend when the staff forces it OR when the follow-up details changed;
        // otherwise the anti-spam guard prevents duplicate texts.
        $smsEnabled = $validated['sms_enabled'] ?? true;
        $forceSms = (bool) ($validated['force_sms'] ?? false);

        if ($smsEnabled) {
            $this->maybeSendSms($reminder, $mobile, $rhuId, $forceSms || $contentChanged);
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

    /**
     * POST /api/v1/follow-up-reminders/{id}/resend-sms
     * Manual resend from the SOAP follow-up section — always bypasses the
     * anti-spam guard and records a fresh send attempt.
     */
    public function resendSms(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$this->isStaff($user)) {
            return response()->json(['message' => 'Only RHU staff can resend reminders.'], 403);
        }

        $reminder = FollowUpReminder::findOrFail($id);

        if (
            !$user->isGlobalRhuScope()
            && (int) $reminder->rhu_id !== (int) ($user->effectiveRhuId() ?? 0)
        ) {
            return response()->json(['message' => 'You can only resend reminders for your assigned RHU.'], 403);
        }

        $this->maybeSendSms($reminder, $reminder->mobile_number, $reminder->rhu_id, true);

        return response()->json([
            'message' => 'SMS resend attempted.',
            'data' => $reminder->fresh(['user', 'rhu', 'createdBy']),
        ]);
    }

    /**
     * True when any patient-facing follow-up detail changed versus the stored
     * reminder. Used to decide whether a new SMS should go out on save.
     */
    private function followUpContentChanged(?FollowUpReminder $existing, array $payload): bool
    {
        if (!$existing) {
            return false; // brand-new reminder; the normal send path handles it
        }

        foreach (['follow_up_date', 'follow_up_time', 'reason', 'instructions', 'mobile_number'] as $key) {
            $new = $payload[$key] ?? null;
            $old = $existing->{$key} ?? null;

            if ($key === 'follow_up_date') {
                $new = $new ? Carbon::parse($new)->format('Y-m-d') : '';
                $old = $old ? Carbon::parse($old)->format('Y-m-d') : '';
            } elseif ($key === 'follow_up_time') {
                $new = substr((string) $new, 0, 5);
                $old = substr((string) $old, 0, 5);
            } else {
                $new = trim((string) $new);
                $old = trim((string) $old);
            }

            if ($new !== $old) {
                return true;
            }
        }

        return false;
    }

    private function maybeSendSms(
        FollowUpReminder $reminder,
        ?string $mobile,
        ?int $rhuId,
        bool $force = false
    ): void {
        $normalized = $this->normalizePhone($mobile);

        if (!$normalized) {
            $reminder->update(['sms_status' => 'no_mobile']);
            return;
        }

        // Anti-spam: once an SMS has actually been sent, don't resend unless the
        // caller forces it (manual resend or changed follow-up details).
        if (!$force && $reminder->sms_sent_at) {
            return;
        }

        try {
            $message = $this->buildSmsMessage($reminder, $rhuId);

            // SmsService always creates a fresh sms_logs row and never throws.
            $log = $this->sms->send($normalized, $message, 'followup_reminder', $reminder->user_id);

            $reminder->fill([
                'sms_status' => $log->status,
                'sms_sent_at' => $log->status === 'sent' ? now() : null,
                'sms_error' => $log->status === 'failed'
                    ? ($log->error_message ?? 'SMS failed')
                    : null,
            ]);

            // Optional tracking columns (only if the additive migration has run).
            if (Schema::hasColumn('follow_up_reminders', 'sms_last_attempt_at')) {
                $reminder->sms_last_attempt_at = now();
            }
            if (Schema::hasColumn('follow_up_reminders', 'sms_log_id')) {
                $reminder->sms_log_id = $log->id;
            }

            $reminder->save();
        } catch (\Throwable $e) {
            // Never break the SOAP save because of SMS.
            $reminder->fill([
                'sms_status' => 'failed',
                'sms_error' => $e->getMessage(),
            ]);

            if (Schema::hasColumn('follow_up_reminders', 'sms_last_attempt_at')) {
                $reminder->sms_last_attempt_at = now();
            }

            $reminder->save();
        }
    }

    private function buildSmsMessage(FollowUpReminder $reminder, ?int $rhuId): string
    {
        $date = $reminder->follow_up_date
            ? Carbon::parse($reminder->follow_up_date)->format('M d, Y')
            : 'your scheduled date';

        $time = $reminder->follow_up_time
            ? substr((string) $reminder->follow_up_time, 0, 5)
            : 'the set time';

        $reason = trim((string) ($reminder->reason ?: $reminder->instructions ?: 'follow-up consultation'));

        // Keep it short for a single SMS segment where possible.
        return "Ka-Agapay RHU Reminder: You have a follow-up consultation scheduled on "
            . "{$date} at {$time}. Reason: {$reason}. Please visit your assigned RHU. "
            . "If you cannot attend, contact the RHU.";
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            $string = trim((string) ($value ?? ''));
            if ($string !== '') {
                return $string;
            }
        }

        return null;
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

    /**
     * Normalise a PH mobile number to Semaphore's accepted 09XXXXXXXXX format.
     * Accepts 09XXXXXXXXX, +639XXXXXXXXX, and 639XXXXXXXXX.
     */
    private function normalizePhone(?string $number): ?string
    {
        if (!$number) {
            return null;
        }

        $n = preg_replace('/[^\d+]/', '', trim($number)) ?? '';

        // 09XXXXXXXXX → already valid.
        if (preg_match('/^09\d{9}$/', $n)) {
            return $n;
        }

        // +639XXXXXXXXX or 639XXXXXXXXX → convert to 09XXXXXXXXX.
        if (preg_match('/^\+?639\d{9}$/', $n)) {
            return '0' . substr(ltrim($n, '+'), 2);
        }

        // 9XXXXXXXXX (missing leading 0) → prepend 0.
        if (preg_match('/^9\d{9}$/', $n)) {
            return '0' . $n;
        }

        return null;
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
