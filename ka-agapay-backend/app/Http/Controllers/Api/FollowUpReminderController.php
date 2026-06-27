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
use Illuminate\Validation\ValidationException;

class FollowUpReminderController extends Controller
{
    private const STAFF_ROLES = [
        'admin', 'staff', 'staff_admin', 'rhu_admin', 'mho',
        'super_admin', 'doctor', 'nurse', 'midwife', 'bhw',
    ];

    // Active (still-open) follow-up statuses used for overdue/today/upcoming.
    private const ACTIVE_STATUSES = ['pending', 'scheduled'];

    // An overdue active follow-up older than this many days is treated as missed.
    private const MISSED_GRACE_DAYS = 7;

    public function __construct(private readonly SmsService $sms)
    {
    }

    /**
     * GET /api/v1/follow-up-reminders  (and alias /follow-ups)
     *
     * Lists ALL follow-ups for the staff's RHU with rich filtering. Supports
     * derived statuses (overdue/today/upcoming/missed) in addition to the raw
     * stored statuses, plus search / barangay / assigned-staff / date range.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FollowUpReminder::query()
            ->with(['user', 'rhu', 'createdBy', 'consultation'])
            ->orderByRaw('CASE WHEN status IN (\'pending\', \'scheduled\') THEN 0 ELSE 1 END')
            ->orderByRaw('follow_up_at IS NULL')
            ->orderBy('follow_up_at')
            ->latest('id');

        $this->applyScope($query, $request);
        $this->applyFilters($query, $request);

        return response()->json(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    /**
     * GET /api/v1/follow-up-reminders/summary  (and alias /follow-ups/summary)
     */
    public function summary(Request $request): JsonResponse
    {
        $base = FollowUpReminder::query();
        $this->applyScope($base, $request);

        $today = today();
        $monthStart = now()->startOfMonth();
        $missedCutoff = today()->subDays(self::MISSED_GRACE_DAYS);

        $active = fn ($q) => $q->whereIn('status', self::ACTIVE_STATUSES);

        return response()->json([
            'data' => [
                'total' => (int) (clone $base)->count(),
                'overdue' => (int) (clone $base)
                    ->where($active)
                    ->whereNotNull('follow_up_at')
                    ->whereDate('follow_up_at', '<', $today)
                    ->count(),
                'due_today' => (int) (clone $base)
                    ->where($active)
                    ->whereDate('follow_up_at', $today)
                    ->count(),
                'upcoming' => (int) (clone $base)
                    ->where($active)
                    ->whereDate('follow_up_at', '>', $today)
                    ->count(),
                'completed_this_month' => (int) (clone $base)
                    ->where('status', 'completed')
                    ->where('updated_at', '>=', $monthStart)
                    ->count(),
                'missed' => (int) (clone $base)
                    ->where(function ($q) use ($missedCutoff) {
                        $q->where('status', 'missed')
                            ->orWhere(function ($q2) use ($missedCutoff) {
                                $q2->whereIn('status', self::ACTIVE_STATUSES)
                                    ->whereNotNull('follow_up_at')
                                    ->whereDate('follow_up_at', '<', $missedCutoff);
                            });
                    })
                    ->count(),
            ],
        ]);
    }

    /**
     * Restrict to the staff's RHU (global-scope roles can pass rhu_id/barangay_id).
     */
    private function applyScope($query, Request $request): void
    {
        $user = $request->user();

        if ($user && !$user->isGlobalRhuScope()) {
            $query->where('rhu_id', (int) ($user->effectiveRhuId() ?? 0));
        } elseif ($request->filled('rhu_id')) {
            $query->where('rhu_id', $request->integer('rhu_id'));
        } elseif ($request->filled('barangay_id')) {
            $query->where('rhu_id', $request->integer('barangay_id'));
        }
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('barangay_id')) {
            $query->where('rhu_id', $request->integer('barangay_id'));
        }

        if ($request->filled('assigned_to')) {
            $query->where('created_by', $request->integer('assigned_to'));
        }

        if ($request->filled('urgency') && $request->query('urgency') !== 'all') {
            $query->where('urgency', (string) $request->query('urgency'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));

            $query->where(function ($q) use ($search) {
                $q->where('patient_name', 'like', "%{$search}%")
                    ->orWhere('mobile_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('rhu', fn ($r) => $r->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('follow_up_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('follow_up_at', '<=', $request->query('date_to'));
        }

        $this->applyStatusFilter($query, strtolower((string) $request->query('status', 'all')));
    }

    private function applyStatusFilter($query, string $status): void
    {
        $today = today();

        switch ($status) {
            case 'overdue':
                $query->whereIn('status', self::ACTIVE_STATUSES)
                    ->whereNotNull('follow_up_at')
                    ->whereDate('follow_up_at', '<', $today);
                break;

            case 'today':
            case 'due_today':
                $query->whereIn('status', self::ACTIVE_STATUSES)
                    ->whereDate('follow_up_at', $today);
                break;

            case 'upcoming':
                $query->whereIn('status', self::ACTIVE_STATUSES)
                    ->whereDate('follow_up_at', '>', $today);
                break;

            case 'missed':
                $cutoff = today()->subDays(self::MISSED_GRACE_DAYS);
                $query->where(function ($q) use ($cutoff) {
                    $q->where('status', 'missed')
                        ->orWhere(function ($q2) use ($cutoff) {
                            $q2->whereIn('status', self::ACTIVE_STATUSES)
                                ->whereNotNull('follow_up_at')
                                ->whereDate('follow_up_at', '<', $cutoff);
                        });
                });
                break;

            case 'completed':
            case 'cancelled':
            case 'pending':
            case 'scheduled':
                $query->where('status', $status);
                break;

            case 'all':
            default:
                // No status constraint — the page lists everything.
                break;
        }
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
            'follow_up_type' => ['nullable', Rule::in(['single', 'range'])],
            'follow_up_date' => ['nullable', 'date'],
            'follow_up_start_date' => ['nullable', 'date'],
            'follow_up_end_date' => ['nullable', 'date'],
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

        $mobile = $this->resolveMobileNumber([
            $validated['mobile_number'] ?? null,
            $user?->mobile_number,
            $profile?->mobile_number,
            $profile?->contact_number,
            $profile?->phone_number,
            $profile?->emergency_contact_number,
        ]);

        $rhuId = ($consultation?->appointment?->rhu_id ?? null) ?: ($profile?->barangay_id ?? null);
        $appointmentId = $validated['appointment_id'] ?? $consultation?->appointment_id;

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

        $schedule = $this->normalizeFollowUpSchedule($validated);

        $payload = [
            'appointment_id' => $appointmentId,
            'user_id' => $consultation?->user_id,
            'resident_profile_id' => $profile?->id,
            'rhu_id' => $rhuId,
            'created_by' => $this->userId($request),
            'patient_name' => $user?->full_name,
            'mobile_number' => $mobile,
            'follow_up_at' => $schedule['follow_up_at'],
            'follow_up_type' => $schedule['follow_up_type'],
            'follow_up_date' => $schedule['follow_up_date'],
            'follow_up_start_date' => $schedule['follow_up_start_date'],
            'follow_up_end_date' => $schedule['follow_up_end_date'],
            'follow_up_time' => $schedule['follow_up_time'],
            'reason' => $validated['reason'] ?? null,
            'instructions' => $validated['instructions'] ?? null,
            'urgency' => $validated['urgency'] ?? 'routine',
            'status' => $schedule['follow_up_at'] ? 'scheduled' : 'pending',
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
        $smsResult = null;

        if ($smsEnabled) {
            $shouldAttemptSms = !$existing || $forceSms || $contentChanged;

            if ($shouldAttemptSms) {
                $smsResult = $this->attemptSms($reminder, $mobile);
            }
        }

        return response()->json([
            'message' => $smsResult['message'] ?? 'Follow-up reminder saved.',
            'data' => $reminder->fresh(['user', 'rhu', 'createdBy']),
        ], 201);
    }

    /**
     * PATCH /api/v1/follow-up-reminders/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $reminder = FollowUpReminder::with('consultation.resident.residentProfile')->findOrFail($id);

        $validated = $request->validate([
            'follow_up_type' => ['nullable', Rule::in(['single', 'range'])],
            'follow_up_date' => ['nullable', 'date'],
            'follow_up_start_date' => ['nullable', 'date'],
            'follow_up_end_date' => ['nullable', 'date'],
            'follow_up_time' => ['nullable', 'string', 'max:10'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'urgency' => ['nullable', Rule::in(FollowUpReminder::URGENCY_LEVELS)],
            'sms_enabled' => ['nullable', 'boolean'],
            'force_sms' => ['nullable', 'boolean'],
            'mobile_number' => ['nullable', 'string', 'max:40'],
        ]);

        $consultation = $reminder->consultation;
        $user = $consultation?->resident ?: $reminder->user;
        $profile = $user?->residentProfile;

        $mobile = $this->resolveMobileNumber([
            array_key_exists('mobile_number', $validated) ? $validated['mobile_number'] : $reminder->mobile_number,
            $user?->mobile_number,
            $profile?->mobile_number,
            $profile?->contact_number,
            $profile?->phone_number,
            $profile?->emergency_contact_number,
        ]);

        $schedule = $this->normalizeFollowUpSchedule($validated, $reminder);

        $payload = [
            'mobile_number' => $mobile,
            'follow_up_at' => $schedule['follow_up_at'],
            'follow_up_type' => $schedule['follow_up_type'],
            'follow_up_date' => $schedule['follow_up_date'],
            'follow_up_start_date' => $schedule['follow_up_start_date'],
            'follow_up_end_date' => $schedule['follow_up_end_date'],
            'follow_up_time' => $schedule['follow_up_time'],
            'reason' => array_key_exists('reason', $validated) ? $validated['reason'] : $reminder->reason,
            'instructions' => array_key_exists('instructions', $validated) ? $validated['instructions'] : $reminder->instructions,
            'urgency' => $validated['urgency'] ?? $reminder->urgency ?? 'routine',
            'status' => $schedule['follow_up_date'] ? 'scheduled' : 'pending',
        ];

        $contentChanged = $this->followUpContentChanged($reminder, $payload);

        $reminder->update($payload);

        $smsResult = null;
        if (($validated['sms_enabled'] ?? true) && ($contentChanged || (bool) ($validated['force_sms'] ?? false))) {
            $smsResult = $this->attemptSms($reminder->fresh(), $mobile);
        }

        return response()->json([
            'message' => $smsResult['message'] ?? 'Follow-up reminder saved.',
            'data' => $reminder->fresh(['user', 'rhu', 'createdBy']),
        ]);
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

        $reminder = FollowUpReminder::with('consultation.resident.residentProfile')->findOrFail($id);

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

        $reminder = FollowUpReminder::with('consultation.resident.residentProfile')->findOrFail($id);

        if (
            !$user->isGlobalRhuScope()
            && (int) $reminder->rhu_id !== (int) ($user->effectiveRhuId() ?? 0)
        ) {
            return response()->json(['message' => 'You can only resend reminders for your assigned RHU.'], 403);
        }

        $user = $reminder->consultation?->resident ?: $reminder->user;
        $profile = $user?->residentProfile;

        $mobile = $this->resolveMobileNumber([
            $reminder->mobile_number,
            $user?->mobile_number,
            $profile?->mobile_number,
            $profile?->contact_number,
            $profile?->phone_number,
            $profile?->emergency_contact_number,
        ]);

        if ($mobile !== $reminder->mobile_number) {
            $reminder->update(['mobile_number' => $mobile]);
        }

        $smsResult = $this->attemptSms($reminder->fresh(), $mobile);

        return response()->json([
            'message' => $smsResult['message'] ?? 'SMS resend attempted.',
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

        foreach ([
            'follow_up_type',
            'follow_up_date',
            'follow_up_start_date',
            'follow_up_end_date',
            'follow_up_time',
            'reason',
            'instructions',
            'mobile_number',
        ] as $key) {
            $new = $payload[$key] ?? null;
            $old = $existing->{$key} ?? null;

            if (in_array($key, ['follow_up_date', 'follow_up_start_date', 'follow_up_end_date'], true)) {
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

    private function attemptSms(FollowUpReminder $reminder, ?string $mobile): array
    {
        if (!$mobile) {
            $this->updateReminderSmsFields($reminder, [
                'sms_status' => 'not_sent',
                'sms_sent_at' => null,
                'sms_error_message' => 'The patient has no valid mobile number.',
            ]);

            return [
                'status' => 'not_sent',
                'message' => 'Follow-up saved, but SMS was not sent because the patient has no valid mobile number.',
            ];
        }

        try {
            $message = $this->buildSmsMessage($reminder);

            // SmsService creates the sms_logs row before contacting Semaphore.
            $log = $this->sms->send($mobile, $message, 'follow_up_reminder', $reminder->user_id);

            $this->updateReminderSmsFields($reminder, [
                'sms_status' => $log->status,
                'sms_sent_at' => $log->status === 'sent' ? now() : null,
                'sms_last_attempt_at' => now(),
                'sms_log_id' => $log->id,
                'sms_error_message' => $log->status === 'failed'
                    ? ($log->error_message ?? 'SMS failed')
                    : null,
            ]);

            return ['status' => $log->status, 'message' => 'Follow-up reminder saved.'];
        } catch (\Throwable $e) {
            $this->updateReminderSmsFields($reminder, [
                'sms_status' => 'failed',
                'sms_sent_at' => null,
                'sms_last_attempt_at' => now(),
                'sms_error_message' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'message' => 'Follow-up reminder saved.'];
        }
    }

    private function updateReminderSmsFields(FollowUpReminder $reminder, array $fields): void
    {
        $status = in_array($fields['sms_status'] ?? '', FollowUpReminder::SMS_STATUSES, true)
            ? $fields['sms_status']
            : 'pending';

        $updates = [
            'sms_status' => $status,
            'sms_sent_at' => $status === 'sent' ? ($fields['sms_sent_at'] ?? now()) : null,
        ];

        foreach (['sms_last_attempt_at', 'sms_log_id', 'sms_error_message'] as $column) {
            if (array_key_exists($column, $fields) && Schema::hasColumn('follow_up_reminders', $column)) {
                $updates[$column] = $fields[$column];
            }
        }

        if (Schema::hasColumn('follow_up_reminders', 'sms_error')) {
            $updates['sms_error'] = $fields['sms_error_message'] ?? null;
        }

        $reminder->update($updates);
    }

    private function buildSmsMessage(FollowUpReminder $reminder): string
    {
        if ((string) ($reminder->follow_up_type ?? 'single') === 'range') {
            $start = $reminder->follow_up_start_date
                ? Carbon::parse($reminder->follow_up_start_date)->format('M d, Y')
                : ($reminder->follow_up_date
                    ? Carbon::parse($reminder->follow_up_date)->format('M d, Y')
                    : 'the start date');

            $end = $reminder->follow_up_end_date
                ? Carbon::parse($reminder->follow_up_end_date)->format('M d, Y')
                : $start;

            return "Ka-Agapay RHU Reminder: Your follow-up consultation is scheduled from "
                . "{$start} to {$end}. Please visit your assigned RHU.";
        }

        $date = $reminder->follow_up_date
            ? Carbon::parse($reminder->follow_up_date)->format('M d, Y')
            : 'your scheduled date';

        $time = $reminder->follow_up_time
            ? substr((string) $reminder->follow_up_time, 0, 5)
            : 'the set time';

        $reason = trim((string) $reminder->reason);

        if ($reason !== '') {
            return "Ka-Agapay RHU Reminder: You have a follow-up consultation scheduled on "
                . "{$date} at {$time}. Reason: {$reason}. Please visit your assigned RHU.";
        }

        return "Ka-Agapay RHU Reminder: You have a follow-up consultation scheduled on "
            . "{$date} at {$time}. Please visit your assigned RHU.";
    }

    private function resolveMobileNumber(array $values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->normalizePhone($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeFollowUpSchedule(array $validated, ?FollowUpReminder $existing = null): array
    {
        $type = $validated['follow_up_type']
            ?? $existing?->follow_up_type
            ?? (!empty($validated['follow_up_start_date']) || !empty($validated['follow_up_end_date'])
                ? 'range'
                : 'single');

        $type = $type === 'range' ? 'range' : 'single';

        $time = array_key_exists('follow_up_time', $validated)
            ? ($validated['follow_up_time'] ?? null)
            : ($existing?->follow_up_time ?? null);

        if ($type === 'range') {
            $start = $validated['follow_up_start_date']
                ?? $validated['follow_up_date']
                ?? optional($existing?->follow_up_start_date)->toDateString()
                ?? optional($existing?->follow_up_date)->toDateString();

            $end = $validated['follow_up_end_date']
                ?? optional($existing?->follow_up_end_date)->toDateString();

            if (!$start || !$end) {
                throw ValidationException::withMessages([
                    'follow_up_start_date' => ['Start date and end date are required for a date range follow-up.'],
                ]);
            }

            if (Carbon::parse($end)->lt(Carbon::parse($start))) {
                throw ValidationException::withMessages([
                    'follow_up_end_date' => ['End date must be the same day as or after the start date.'],
                ]);
            }

            $startDate = Carbon::parse($start)->toDateString();
            $endDate = Carbon::parse($end)->toDateString();

            return [
                'follow_up_type' => 'range',
                'follow_up_date' => $startDate,
                'follow_up_start_date' => $startDate,
                'follow_up_end_date' => $endDate,
                'follow_up_time' => $time,
                'follow_up_at' => $this->combineDateTime($startDate, $time),
            ];
        }

        $date = $validated['follow_up_date']
            ?? $validated['follow_up_start_date']
            ?? optional($existing?->follow_up_date)->toDateString()
            ?? optional($existing?->follow_up_start_date)->toDateString();

        if (!$date) {
            throw ValidationException::withMessages([
                'follow_up_date' => ['Follow-up date is required.'],
            ]);
        }

        $date = Carbon::parse($date)->toDateString();

        return [
            'follow_up_type' => 'single',
            'follow_up_date' => $date,
            'follow_up_start_date' => $date,
            'follow_up_end_date' => null,
            'follow_up_time' => $time,
            'follow_up_at' => $this->combineDateTime($date, $time),
        ];
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
