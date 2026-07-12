<?php
// app/Services/Notification/EventSmsService.php
//
// SMS blast for published CMS events/announcements + the 3-days-before
// event reminder.
//
// Recipient scoping mirrors what residents actually see in the app:
//   - barangay_target 'all'  → every eligible resident
//   - barangay_target CSV    → residents of those barangays only
//   - visibility rhu1/rhu2   → additionally limited to that facility's
//                              barangays (via barangays.rhu_id)
// Eligible = active resident/patient accounts with a valid PH mobile.
//
// Delivery reuses the EXISTING pipeline: SmsLog rows are created 'queued',
// SemaphoreSmsService::sendBulk() fires ONE provider call, and each row is
// updated from the provider response — identical vocabulary and SMS-Center
// visibility as manual sends. Never throws into the publish flow: any
// failure is logged and publishing succeeds regardless.
//
// Idempotency: events.sms_sent_at / events.reminder_sms_sent_at are set the
// moment a blast starts, so re-publishing or scheduler re-runs can never
// text residents twice for the same post.

namespace App\Services\Notification;

use App\Models\Event;
use App\Models\SmsLog;
use App\Services\Sms\SemaphoreSmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EventSmsService
{
    /** Hard cost/safety cap per blast — same ceiling as the SMS Center. */
    private const MAX_RECIPIENTS = 1000;

    public function __construct(private readonly SemaphoreSmsService $semaphore)
    {
    }

    /** Post-publish SMS (events AND announcements). Returns recipients texted. */
    public function sendPublishSms(Event $event): int
    {
        if (!$this->guardColumns() || !$event->is_published || $event->sms_sent_at !== null) {
            return 0;
        }

        $sent = $this->blast($event, $this->publishMessage($event), 'event_published');

        // Mark even when 0 recipients matched — the decision was made once.
        $event->forceFill(['sms_sent_at' => now()])->save();

        return $sent;
    }

    /** 3-days-before reminder (events/programs with a future start only). */
    public function sendReminderSms(Event $event): int
    {
        if (!$this->guardColumns() || !$event->is_published || $event->reminder_sms_sent_at !== null) {
            return 0;
        }

        if (($event->event_type ?? 'event') === 'announcement') {
            return 0;
        }

        $sent = $this->blast($event, $this->reminderMessage($event), 'event_reminder');

        $event->forceFill(['reminder_sms_sent_at' => now()])->save();

        return $sent;
    }

    // ── Message composition ──────────────────────────────────────────────

    private function publishMessage(Event $event): string
    {
        // Staff wrote the SMS Summary specifically for texting — honor it.
        $summary = trim((string) ($event->sms_summary ?? ''));

        if ($summary !== '') {
            return $summary;
        }

        $isAnnouncement = ($event->event_type ?? 'event') === 'announcement';
        $title = trim((string) $event->title);

        if ($isAnnouncement) {
            return "RHU Malasiqui Advisory: {$title}. Open the Ka-Agapay app for details.";
        }

        $parts = ["RHU Malasiqui: {$title}"];

        $start = $event->event_date ?? $event->starts_at;
        if ($start) {
            $parts[] = 'on ' . $start->format('M j, g:iA');
        }

        if (trim((string) $event->location) !== '') {
            $parts[] = 'at ' . trim((string) $event->location);
        }

        return implode(' ', $parts) . '. See the Ka-Agapay app to register.';
    }

    private function reminderMessage(Event $event): string
    {
        $title = trim((string) $event->title);
        $start = $event->event_date ?? $event->starts_at;

        $when = $start ? $start->format('M j, g:iA') : 'soon';
        $where = trim((string) $event->location);

        $message = "Paalala mula sa RHU Malasiqui: \"{$title}\" is in 3 days ({$when})";

        if ($where !== '') {
            $message .= " at {$where}";
        }

        return $message . '. Hihintayin po namin kayo.';
    }

    // ── Delivery ─────────────────────────────────────────────────────────

    private function blast(Event $event, string $message, string $type): int
    {
        try {
            $recipients = $this->resolveRecipients($event);

            if (count($recipients) === 0) {
                Log::info('[EventSmsService] No eligible recipients.', [
                    'event_id' => $event->id,
                    'type' => $type,
                ]);

                return 0;
            }

            // 1 — log every message as 'queued' first (same vocabulary as the
            // SMS Center) so the attempt is visible even if the provider fails.
            $logs = collect();

            foreach ($recipients as $recipient) {
                $logs->push(SmsLog::create([
                    'user_id' => $recipient['user_id'],
                    'sent_by' => $event->created_by,
                    'recipient_name' => $recipient['name'],
                    'mobile_number' => $recipient['mobile'],
                    'message' => $message,
                    'mode' => 'event',
                    'notification_type' => $type,
                    'provider' => $this->semaphore->providerName(),
                    'status' => 'queued',
                    'sent_at' => null,
                ]));
            }

            // 2 — one bulk provider call for the whole audience.
            $numbers = $logs->pluck('mobile_number')->unique()->values()->all();
            $responses = $this->semaphore->sendBulk($numbers, $message);

            // 3 — flip logs from the provider response (accepted = sent).
            $accepted = collect($responses)
                ->map(fn ($r) => data_get($r, 'message_id') ?? data_get($r, 'id'))
                ->filter()
                ->isNotEmpty();

            foreach ($logs as $log) {
                $log->update([
                    'status' => $accepted ? 'sent' : 'queued',
                    'sent_at' => $accepted ? now() : null,
                ]);
            }

            Log::info('[EventSmsService] Blast dispatched.', [
                'event_id' => $event->id,
                'type' => $type,
                'recipients' => $logs->count(),
            ]);

            return $logs->count();
        } catch (\Throwable $e) {
            Log::error('[EventSmsService] Blast failed (publish flow unaffected).', [
                'event_id' => $event->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Eligible residents for this post: active resident/patient accounts with
     * a valid mobile, inside the post's barangay + facility scope.
     *
     * @return array<int, array{user_id: int|null, name: string, mobile: string}>
     */
    private function resolveRecipients(Event $event): array
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'mobile_number')) {
            return [];
        }

        $query = DB::table('users')
            ->whereNull('deleted_at')
            ->whereNotNull('mobile_number')
            ->where('mobile_number', '!=', '');

        if (Schema::hasColumn('users', 'account_status')) {
            $query->where('account_status', 'active');
        }

        // Residents/patients only — never text staff about resident posts.
        $residentRoleIds = $this->residentRoleIds();
        if (count($residentRoleIds) > 0 && Schema::hasColumn('users', 'role_id')) {
            $query->whereIn('role_id', $residentRoleIds);
        }

        // Barangay scope: 'all' → skip; CSV → exact-name membership.
        $barangays = $this->targetBarangays($event);
        if ($barangays !== null && Schema::hasColumn('users', 'barangay')) {
            $query->whereIn('barangay', $barangays);
        }

        // Facility scope (visibility rhu1/rhu2) → that RHU's barangays only.
        $rhuId = match ($event->visibility) {
            'rhu1' => 1,
            'rhu2' => 2,
            default => null,
        };

        if (
            $rhuId !== null &&
            Schema::hasColumn('users', 'barangay') &&
            Schema::hasTable('barangays') &&
            Schema::hasColumn('barangays', 'rhu_id')
        ) {
            $query->whereIn('barangay', function ($sub) use ($rhuId) {
                $sub->select('name')->from('barangays')->where('rhu_id', $rhuId);
            });
        }

        return $query
            ->limit(self::MAX_RECIPIENTS)
            ->get()
            ->map(function ($user) {
                $mobile = $this->semaphore->normalizePhoneNumber((string) $user->mobile_number);

                if (!$mobile) {
                    return null;
                }

                $name = trim(implode(' ', array_filter([
                    $user->first_name ?? null,
                    $user->last_name ?? null,
                ]))) ?: 'Resident';

                return [
                    'user_id' => (int) ($user->user_id ?? $user->id ?? 0) ?: null,
                    'name' => $name,
                    'mobile' => $mobile,
                ];
            })
            ->filter()
            ->unique('mobile')
            ->values()
            ->all();
    }

    /** Barangay names targeted by the post, or null for "all barangays". */
    private function targetBarangays(Event $event): ?array
    {
        $target = trim((string) ($event->barangay_target ?? 'all'));

        if ($target === '' || strtolower($target) === 'all') {
            return null;
        }

        $names = array_values(array_filter(array_map(
            fn (string $name) => trim($name),
            explode(',', $target)
        )));

        return count($names) > 0 ? $names : null;
    }

    private function residentRoleIds(): array
    {
        if (!Schema::hasTable('user_roles')) {
            return [];
        }

        $key = Schema::hasColumn('user_roles', 'role_id') ? 'role_id' : 'id';

        return DB::table('user_roles')
            ->whereIn(DB::raw("LOWER(REPLACE(name, ' ', '_'))"), ['resident', 'patient'])
            ->pluck($key)
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function guardColumns(): bool
    {
        return Schema::hasTable('events')
            && Schema::hasColumn('events', 'sms_sent_at')
            && Schema::hasColumn('events', 'reminder_sms_sent_at')
            && Schema::hasTable('sms_logs');
    }
}
