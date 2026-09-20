<?php
// tests/Feature/Assistant/AnswerBookTest.php
//
// The answer book exists because the model invents plausible-sounding steps
// for screens it has never seen. These tests hold two things:
//
//   1. the right entry is found for the way staff actually phrase a question,
//      in English and in Tagalog;
//   2. the entries stay usable — every screen has steps, and the wording
//      never slides back into the vagueness the book was written to replace.
//
// If a screen changes and its entry does not, the test suite will not catch
// that. The answer book is documentation with teeth, and like any
// documentation it is only as honest as the person editing it.

namespace Tests\Feature\Assistant;

use App\Services\Ai\AnswerBook;
use PHPUnit\Framework\TestCase;

class AnswerBookTest extends TestCase
{
    private AnswerBook $book;

    /** The wording that made staff stop trusting the assistant. */
    private const VAGUE = ['usually', 'probably', 'somewhere', 'might be', 'i think'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->book = new AnswerBook();
    }

    /**
     * @dataProvider realQuestions
     */
    public function test_it_finds_the_screen_staff_are_asking_about(string $question, string $expected): void
    {
        $entry = $this->book->find($question);

        $this->assertNotNull($entry, "No entry matched: {$question}");
        $this->assertSame($expected, $entry['key'], "Wrong screen for: {$question}");
    }

    public static function realQuestions(): array
    {
        return [
            'release a prescription' => ['how do I release a prescription?', 'prescriptions'],
            // Dispensing happens on the prescription, not in Inventory —
            // Inventory is for deliveries and counts.
            'dispense medicine' => ['paano mag-dispense ng gamot?', 'prescriptions'],
            'stock delivery' => ['how do I record a stock delivery', 'inventory'],
            'low stock' => ['where do I see low stock items', 'inventory'],
            'lab request' => ['where do I make a lab request', 'prescriptions'],
            'approve appointment' => ['how to approve an appointment', 'appointments'],
            'call next patient' => ['how do I call next in the queue', 'queue'],
            'pila' => ['paano gamitin ang pila', 'queue'],
            'approve a sign-up' => ['how do I approve a registration', 'registrations'],
            'add an rhu' => ['how do I add a new rhu facility', 'rhus'],
            'publish a post' => ['how do I publish an announcement', 'events'],
            'follow ups' => ['what is the follow-up screen for', 'followups'],
            'monthly report' => ['how do I export the monthly report', 'reports'],
            'team chat' => ['how do I use team chat', 'teamchat'],
        ];
    }

    public function test_an_unrelated_question_is_left_to_the_model(): void
    {
        $this->assertNull($this->book->find('what is the weather today'));
        $this->assertNull($this->book->find('kumusta ka'));
    }

    public function test_every_entry_names_real_steps_and_avoids_vague_wording(): void
    {
        $screens = ['queue', 'appointments', 'prescriptions', 'patients', 'registrations',
            'inventory', 'followups', 'events', 'reports', 'users', 'rhus', 'teamchat'];

        foreach ($screens as $screen) {
            $entry = $this->book->find($screen === 'patients' ? 'patient registry' : $screen);

            $this->assertNotNull($entry, "No entry for {$screen}");
            $this->assertNotEmpty($entry['steps'], "{$screen} has no steps");
            $this->assertNotEmpty($entry['summary'], "{$screen} has no summary");

            $text = mb_strtolower($this->book->plainAnswer($entry));

            foreach (self::VAGUE as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $text,
                    "The {$screen} entry should be specific; it contains \"{$phrase}\"."
                );
            }
        }
    }

    public function test_the_plain_answer_is_usable_on_its_own(): void
    {
        // This is what staff get when the AI service is unreachable, so it has
        // to stand alone: the screen, what it is for, and the steps in order.
        $entry = $this->book->find('how do I release a prescription?');
        $answer = $this->book->plainAnswer($entry);

        $this->assertStringContainsString('E-Prescription', $answer);
        $this->assertStringContainsString('1.', $answer);
        $this->assertStringContainsString('received the medicine', $answer);
    }

    public function test_the_model_brief_tells_it_to_use_this_and_not_its_memory(): void
    {
        $brief = $this->book->brief($this->book->find('how do I call next in the queue'));

        $this->assertStringContainsString('KA-AGAPAY REFERENCE', $brief);
        $this->assertStringContainsString('not from memory', $brief);
        $this->assertStringContainsString('Call Next', $brief);
    }
}
