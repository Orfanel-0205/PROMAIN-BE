<?php

namespace Tests\Unit;

use App\Models\FollowUpReminder;
use App\Services\Notification\FollowUpReminderSms;
use App\Support\LocalTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Follow-up reminders, and the Philippine-time rules they depend on.
 *
 * Two real bugs are held here. A follow-up entered as 9:00 AM was stored as
 * 9:00 UTC and shown on the admin as 5:00 PM. And every reminder ran on the
 * scheduler's UTC clock, so the appointment reminder meant for the evening
 * before reached patients at 1:00 AM on the day itself, saying "tomorrow".
 */
class FollowUpRemindersTest extends TestCase
{
    private const APP = __DIR__ . '/../../app';

    private function source(string $file): string
    {
        return (string) file_get_contents(self::APP . '/' . $file);
    }

    private function followUp(array $attributes): FollowUpReminder
    {
        return (new FollowUpReminder())->forceFill($attributes + [
            'follow_up_type' => 'single',
            'sms_enabled' => true,
            'reason' => 'Lab result: positive for something private',
        ]);
    }

    #[Test]
    #[TestDox('a time staff enter is Philippine time, stored as UTC')]
    public function entered_times_are_philippine_time(): void
    {
        config(['app.local_timezone' => 'Asia/Manila']);

        $this->assertSame('2026-10-07 01:00:00', LocalTime::toUtc('2026-10-07', '09:00')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-07 01:00:00', LocalTime::toUtc('2026-10-07', '09:00:00')->format('Y-m-d H:i:s'));

        // Before 8:00 AM it is the previous day in UTC; the follow-up's own
        // date column is what the tabs compare, not this instant's date.
        $this->assertSame('2026-10-06 23:30:00', LocalTime::toUtc('2026-10-07', '07:30')->format('Y-m-d H:i:s'));

        // No time entered: 9:00 AM, as before.
        $this->assertSame('2026-10-07 01:00:00', LocalTime::toUtc('2026-10-07')->format('Y-m-d H:i:s'));
    }

    #[Test]
    #[TestDox('reminders go three days before and the day before by SMS, and on the day by app')]
    public function the_stages(): void
    {
        $this->assertSame(['three_days_before' => 3, 'day_before' => 1, 'day_of' => 0], FollowUpReminder::REMINDER_STAGES);
        $this->assertSame(['three_days_before', 'day_before'], FollowUpReminder::SMS_REMINDER_STAGES);

        // The app notification keeps "day_before" rather than folding it
        // into "three days before", which the old code did for any stage
        // that was not "day_of".
        $this->assertStringContainsString(
            "in_array(\$stage, ['day_of', 'day_before'], true)",
            $this->source('Services/Notification/NotificationService.php')
        );
    }

    #[Test]
    #[TestDox('the texts cost one credit, say when, and keep the reason private')]
    public function the_texts(): void
    {
        $single = $this->followUp(['follow_up_date' => '2026-10-07', 'follow_up_start_date' => '2026-10-07', 'follow_up_time' => '09:00:00']);
        $range = $this->followUp([
            'follow_up_type' => 'range',
            'follow_up_date' => '2026-10-07',
            'follow_up_start_date' => '2026-10-07',
            'follow_up_end_date' => '2026-10-10',
        ]);

        $texts = [
            'single, 3 days' => FollowUpReminderSms::message($single, 'three_days_before'),
            'single, day before' => FollowUpReminderSms::message($single, 'day_before'),
            'range, 3 days' => FollowUpReminderSms::message($range, 'three_days_before'),
            'range, day before' => FollowUpReminderSms::message($range, 'day_before'),
        ];

        foreach ($texts as $name => $text) {
            $this->assertLessThanOrEqual(160, strlen($text), "{$name} costs two credits: {$text}");
            $this->assertStringNotContainsStringIgnoringCase('positive', $text, "{$name} includes the reason.");
            $this->assertStringContainsString('Wed, Oct 7', $text, $name);
        }

        $this->assertStringContainsString('in 3 days', $texts['single, 3 days']);
        $this->assertStringContainsString('9:00 AM', $texts['single, 3 days']);
        $this->assertStringContainsString('tomorrow', $texts['single, day before']);
        $this->assertStringContainsString('Sat, Oct 10', $texts['range, day before']);
    }

    #[Test]
    #[TestDox('a follow-up with SMS turned off is not texted')]
    public function sms_off_is_respected(): void
    {
        $source = $this->source('Services/Notification/FollowUpReminderSms.php');

        $this->assertStringContainsString("\$reminder->sms_enabled === false", $source);
        $this->assertStringContainsString("isset(\$handled[\$stage])", $source, 'A stage can be texted twice.');
    }

    #[Test]
    #[TestDox('every reminder job runs on Philippine time and counts Philippine days')]
    public function reminders_run_on_philippine_time(): void
    {
        $kernel = $this->source('Console/Kernel.php');

        foreach ([
            "appointments:send-reminders --stage=day_before",
            "appointments:send-reminders --stage=day_of",
            "followups:send-reminders",
            "events:send-reminders",
        ] as $command) {
            $block = (string) substr($kernel, (int) strpos($kernel, "command('{$command}')"), 300);
            $this->assertStringContainsString('->timezone(LocalTime::zone())', $block, "{$command} runs on the UTC clock.");
        }

        $this->assertStringContainsString('LocalTime::today()', $this->source('Console/Commands/SendFollowUpPushReminders.php'));
        $this->assertStringContainsString('LocalTime::today()', $this->source('Console/Commands/SendAppointmentReminders.php'));
        $this->assertStringContainsString('LocalTime::today()', $this->source('Console/Commands/SendEventReminders.php'));
    }

    #[Test]
    #[TestDox('the follow-up tabs use the Philippine date, and the cards count the tabs')]
    public function tabs_use_philippine_dates(): void
    {
        $controller = $this->source('Http/Controllers/Api/FollowUpReminderController.php');

        $this->assertStringContainsString('return LocalTime::toUtc($date, $time);', $controller);

        $filter = (string) substr($controller, (int) strpos($controller, 'private function applyStatusFilter('), 2500);
        $this->assertStringContainsString('LocalTime::today()', $filter);
        $this->assertStringNotContainsString("whereDate('follow_up_at'", $filter);

        // A card saying "3 overdue" over a tab listing none is the confusion
        // this page started with.
        $summary = (string) substr($controller, (int) strpos($controller, 'public function summary('), 1500);
        $this->assertStringContainsString('$this->applyStatusFilter($query, $tab)', $summary);
    }
}
