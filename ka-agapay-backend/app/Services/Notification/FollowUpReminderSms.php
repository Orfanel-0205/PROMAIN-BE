<?php
// app/Services/Notification/FollowUpReminderSms.php

namespace App\Services\Notification;

use App\Models\FollowUpReminder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The reminder texts before a follow-up: three days before and the day before.
 *
 * Most patients with a follow-up have no app account -- staff create the
 * follow-up from a consultation with the patient's name and number -- so an
 * app notification alone reaches almost nobody. The text does.
 *
 * Sent through SmsService exactly as the follow-up's first text is; how a
 * message is sent is not this class's business.
 *
 *   Once per stage. reminders_sent records each stage that was handled, so
 *   a rerun of the daily job, or two runs on one day, never texts twice.
 *
 *   Only when the follow-up has SMS on (staff set this per follow-up).
 *
 *   One credit. Under 160 characters, and without the reason or diagnosis:
 *   a family phone is often shared, and the patient already has the
 *   details from the first text.
 */
class FollowUpReminderSms
{
    public function __construct(private readonly SmsService $sms) {}

    /**
     * Text the patient for this stage, unless it was already handled.
     *
     * @return string sent | failed | no_mobile | disabled | already
     */
    public function send(FollowUpReminder $reminder, string $stage): string
    {
        $handled = (array) ($reminder->reminders_sent ?? []);

        if (isset($handled[$stage])) {
            return 'already';
        }

        if ($reminder->sms_enabled === false) {
            return $this->record($reminder, $stage, 'disabled');
        }

        $mobile = self::mobile($reminder);

        if ($mobile === null) {
            return $this->record($reminder, $stage, 'no_mobile');
        }

        try {
            $log = $this->sms->send($mobile, self::message($reminder, $stage), 'follow_up_reminder', $reminder->user_id);
            $status = (string) $log->status === 'sent' ? 'sent' : 'failed';

            return $this->record($reminder, $stage, $status, $log->id ?? null);
        } catch (\Throwable $e) {
            Log::warning('[FollowUpReminderSms] Reminder text failed', [
                'follow_up_id' => $reminder->id,
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);

            return $this->record($reminder, $stage, 'failed');
        }
    }

    /** The text for a stage. */
    public static function message(FollowUpReminder $reminder, string $stage): string
    {
        $start = self::day($reminder->follow_up_start_date ?? $reminder->follow_up_date);
        $isRange = (string) ($reminder->follow_up_type ?? 'single') === 'range';

        if ($isRange) {
            $end = self::day($reminder->follow_up_end_date) ?? $start;

            $when = $stage === 'day_before'
                ? "starts tomorrow, {$start}, until {$end}"
                : "is from {$start} to {$end}, starting in 3 days";
        } else {
            $time = self::clock($reminder->follow_up_time);
            $at = $time !== null ? " at {$time}" : '';

            $when = $stage === 'day_before'
                ? "is tomorrow, {$start}{$at}"
                : "is on {$start}{$at}, in 3 days";
        }

        return "Ka-Agapay RHU Reminder: Your follow-up consultation {$when}. Please visit your assigned RHU.";
    }

    /** 09XXXXXXXXX from the follow-up, or from the patient's account. */
    public static function mobile(FollowUpReminder $reminder): ?string
    {
        foreach ([$reminder->mobile_number, $reminder->user?->mobile_number] as $raw) {
            $digits = preg_replace('/\D/', '', (string) $raw) ?? '';

            if (preg_match('/^63(9\d{9})$/', $digits, $m) === 1) {
                $digits = '0' . $m[1];
            }

            if (preg_match('/^09\d{9}$/', $digits) === 1) {
                return $digits;
            }
        }

        return null;
    }

    private function record(FollowUpReminder $reminder, string $stage, string $outcome, mixed $smsLogId = null): string
    {
        $handled = (array) ($reminder->reminders_sent ?? []);
        $handled[$stage] = array_filter([
            'at' => now()->toIso8601String(),
            'sms' => $outcome,
            'sms_log_id' => $smsLogId,
        ], fn ($value) => $value !== null);

        // Quietly: this is bookkeeping, not an edit anyone should be told about.
        $reminder->forceFill(['reminders_sent' => $handled])->saveQuietly();

        return $outcome;
    }

    /** "Tue, Oct 7". */
    private static function day(mixed $date): ?string
    {
        return $date ? Carbon::parse($date)->format('D, M j') : null;
    }

    /** "9:00 AM" from "09:00:00". */
    private static function clock(mixed $time): ?string
    {
        if ($time === null || trim((string) $time) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('H:i', substr(trim((string) $time), 0, 5))->format('g:i A');
        } catch (\Throwable) {
            return null;
        }
    }
}
