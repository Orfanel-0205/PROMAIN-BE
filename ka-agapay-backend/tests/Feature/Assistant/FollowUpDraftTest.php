<?php
// tests/Feature/Assistant/FollowUpDraftTest.php
//
// The assistant can fill in a follow-up schedule, and only a schedule.
//
// Staff type the same follow-up arrangement dozens of times a week, which is
// exactly the work worth handing to an assistant. What is NOT worth handing to
// it is anything a clinician decides: a diagnosis, a medicine, a dose, or the
// care instructions a patient goes home with. A value like that sitting ready
// in a form is one distracted click from reaching a patient, so these tests
// hold the line in code rather than trusting the wording of a prompt.

namespace Tests\Feature\Assistant;

use App\Services\Ai\FormDraftParser;
use Tests\TestCase;

class FollowUpDraftTest extends TestCase
{
    private FormDraftParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new FormDraftParser();
    }

    public function test_a_drafted_schedule_becomes_form_fields(): void
    {
        $draft = $this->parser->parse(
            "Here is the follow-up po:\n\n"
            . "**Follow-up Type:** Single\n"
            . "**Follow-up Date:** 2026-10-05 (Monday)\n"
            . "**Follow-up Time:** 09:00 AM\n"
            . "**Urgency:** Priority\n"
            . "**Reason:** Wound check\n"
            . "**SMS Reminder:** Yes\n\n"
            . 'The instructions are left blank for the doctor to write.'
        );

        $this->assertNotNull($draft);
        $this->assertSame('follow_up', $draft['form']);
        $this->assertSame('single', $draft['fields']['follow_up_type']);
        $this->assertSame('2026-10-05', $draft['fields']['follow_up_date']);
        $this->assertSame('09:00', $draft['fields']['follow_up_time']);
        $this->assertSame('priority', $draft['fields']['urgency']);
        $this->assertSame('Wound check', $draft['fields']['reason']);
        $this->assertSame('1', $draft['fields']['sms_enabled']);
    }

    public function test_the_weekday_written_for_a_human_does_not_break_the_date(): void
    {
        // The model names the weekday so an accidental Sunday is obvious to
        // whoever reads the draft. That aside made every date unparseable, and
        // the whole draft was silently dropped.
        foreach (['2026-10-05 (Monday)', 'October 5, 2026 (Monday)', '2026-10-05'] as $written) {
            $draft = $this->parser->parse("**Follow-up Date:** {$written}");

            $this->assertNotNull($draft, "could not read: {$written}");
            $this->assertSame('2026-10-05', $draft['fields']['follow_up_date']);
        }
    }

    public function test_a_range_keeps_both_ends(): void
    {
        $draft = $this->parser->parse(
            "**Follow-up Type:** Range\n"
            . "**Follow-up Start Date:** 2026-10-05\n"
            . '**Follow-up End Date:** 2026-10-09'
        );

        $this->assertSame('range', $draft['fields']['follow_up_type']);
        $this->assertSame('2026-10-05', $draft['fields']['follow_up_start_date']);
        $this->assertSame('2026-10-09', $draft['fields']['follow_up_end_date']);
    }

    public function test_clinical_content_is_never_carried_into_the_form(): void
    {
        $draft = $this->parser->parse(
            "**Follow-up Date:** 2026-10-05\n"
            . "**Diagnosis:** Community-acquired pneumonia\n"
            . "**Medicine:** Amoxicillin 500mg\n"
            . "**Dose:** 1 capsule three times a day\n"
            . '**Instructions:** Take after meals for seven days'
        );

        // The schedule comes through; everything a clinician decides does not,
        // whatever the model was persuaded to write.
        $this->assertSame(['follow_up_date'], array_keys($draft['fields']));
        $this->assertStringNotContainsString('Amoxicillin', json_encode($draft));
        $this->assertStringNotContainsString('pneumonia', json_encode($draft));
    }

    public function test_an_ordinary_answer_is_not_mistaken_for_a_draft(): void
    {
        // A reply that merely mentions dates must render as ordinary text, or
        // staff get a "fill in the form" button on every other answer.
        $this->assertNull($this->parser->parse('Click the Consultations button, then open the record.'));
        $this->assertNull($this->parser->parse('There are 12 appointments on 2026-10-05.'));
    }

    public function test_a_follow_up_with_no_date_is_not_a_schedule(): void
    {
        // Half a schedule in a form is worse than none: it looks filled in.
        $this->assertNull($this->parser->parse(
            "**Follow-up Type:** Single\n**Urgency:** Routine"
        ));
    }

    public function test_the_words_staff_actually_use_for_urgency_are_understood(): void
    {
        foreach (['Urgent', 'emergency', 'ASAP', 'agad'] as $written) {
            $draft = $this->parser->parse("**Follow-up Date:** 2026-10-05\n**Urgency:** {$written}");

            $this->assertSame('urgent', $draft['fields']['urgency'], "not understood: {$written}");
        }
    }
}
