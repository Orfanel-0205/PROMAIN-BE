<?php

namespace Tests\Unit;

use App\Services\Ai\AnswerBook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The answer book tells staff which button to press. When the admin renames
 * one and the book does not follow, the assistant repeats the old name with
 * complete confidence.
 *
 * That is worse than saying nothing. On 28 September a nurse asked, in
 * Tagalog, how to add a patient to the queue. The book told the model to use
 * "New Ticket" -- a button that has never existed in this system -- so the
 * model discarded the instruction and invented a four-step route through
 * Appointments instead, never mentioning Add Walk-in, which does the job in
 * one press.
 *
 * The same scan found "Stock In" and "Stock Out" in the inventory entry,
 * where the buttons read Restock and Deduct, and "Deactivate" in the users
 * entry, where the button reads Disable.
 *
 * None of that was visible from the backend. The book is correct PHP, the
 * tests passed, and the only symptom was an assistant confidently describing
 * software that does not exist.
 */
class AnswerBookTest extends TestCase
{
    /**
     * Where the admin might be, relative to this repository.
     *
     * The two are separate checkouts and not reliably siblings, so
     * KAAGAPAY_ADMIN_PATH can name the admin root instead. Without either the
     * comparison skips: a developer with only this repository cannot fix a
     * mismatch they cannot see.
     */
    private const ADMIN_PATHS = [
        '/../Rhu-admin-main-1/src',
        '/../rhu-admin-main/src',
        '/../../Rhu-admin-main-1/src',
        '/../../FINAL-SUBMISSION/Rhu-admin-main-1/src',
        '/../../Documents/FINAL-SUBMISSION/Rhu-admin-main-1/src',
    ];

    /**
     * Control names the book uses that are prose rather than labels, or that
     * the admin renders from a translation key this scan cannot resolve.
     *
     * Kept deliberately short. Every addition here is a button this test can
     * no longer protect, so it wants a reason.
     */
    private const NOT_BUTTONS = [
        'Hover',        // an instruction, not a control
        'Work',         // "Work Overdue first" -- prose
        'Click',        // swallowed by the phrase matcher
    ];

    #[Test]
    #[TestDox('every button the answer book names exists in the admin')]
    public function buttons_exist_in_the_admin(): void
    {
        $adminSrc = $this->adminSourcePath();

        if ($adminSrc === null) {
            $this->markTestSkipped(
                'The admin repository was not found next to this one, so the '
                . 'answer book was not checked against it. Set '
                . 'KAAGAPAY_ADMIN_PATH to the admin checkout to run this.'
            );
        }

        $frontend = $this->concatenatedSource($adminSrc);
        $missing = [];

        foreach (AnswerBook::all() as $key => $entry) {
            foreach (($entry['steps'] ?? []) as $step) {
                foreach ($this->labelsIn($step) as $label) {
                    if (str_contains($frontend, $label)) {
                        continue;
                    }

                    $missing[] = sprintf('%s: "%s" (in: %s)', $key, $label, $step);
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "The answer book names controls the admin does not have. Staff "
            . "will be told to press buttons that are not there:\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * Questions that name their feature, and the entry each must reach.
     *
     * find() scores an entry by its LONGEST matching keyword, so a short
     * exact word loses to a long incidental one. "How do I dictate the
     * consultation" was answered with how to run a consultation, because
     * "consultation" is twelve characters and "dictate" is seven. "Paano
     * ako mag-add ng patient sa pila" was answered with how to register a
     * patient, because "patient" is longer than "pila".
     *
     * Both questions say what they want in their first few words. Adding a
     * keyword to any entry can quietly take a question away from another,
     * and nothing else would notice.
     */
    public static function routedQuestions(): array
    {
        return [
            'english dictation'   => ['How do I use speech to text?', 'speech_to_text'],
            'taglish dictation'   => ['paano mag dikta ng boses sa telemedicine', 'speech_to_text'],
            'dictate a consult'   => ['how do I dictate the consultation', 'speech_to_text'],
            'microphone trouble'  => ['the microphone is not picking up words', 'speech_to_text'],
            'voice not typing'    => ['can I use my voice instead of typing', 'speech_to_text'],
            'queue in tagalog'    => ['paano ako mag-add ng patient sa pila', 'queue'],
            'starting a call'     => ['how do I start a video call', 'telemedicine'],
        ];
    }

    #[Test]
    #[TestDox('a question reaches the feature it names')]
    #[DataProvider('routedQuestions')]
    public function questions_reach_the_right_entry(string $question, string $expected): void
    {
        $book = new AnswerBook();
        $hit = $book->find($question);

        $this->assertNotNull(
            $hit,
            "No entry matched '{$question}', so the assistant has nothing "
            . 'written to answer from and will improvise instead.'
        );

        $this->assertSame(
            $expected,
            $hit['key'],
            "'{$question}' was routed to the '{$hit['key']}' entry instead of "
            . "'{$expected}'. A keyword added to one entry has taken a question "
            . 'away from another, and nothing else would have noticed.'
        );
    }

    #[Test]
    #[TestDox('every entry can actually be reached by the words staff use')]
    public function entries_have_keywords(): void
    {
        foreach (AnswerBook::all() as $key => $entry) {
            $this->assertNotEmpty(
                $entry['keywords'] ?? [],
                "The '{$key}' entry has no keywords, so no question will ever "
                . 'match it and the written answer can never be used.'
            );

            $this->assertNotEmpty(
                $entry['steps'] ?? [],
                "The '{$key}' entry has no steps, so it tells a reader nothing "
                . 'they can act on.'
            );
        }
    }

    /**
     * Title-case runs that look like a control label.
     *
     * @return array<int, string>
     */
    private function labelsIn(string $step): array
    {
        preg_match_all('/\b([A-Z][a-z]+(?: [A-Z][a-z-]+){1,3})\b/', $step, $matches);

        $labels = [];

        foreach ($matches[1] as $label) {
            $head = explode(' ', $label)[0];

            if (in_array($head, self::NOT_BUTTONS, true)) {
                // "Click Approve" -- drop the verb and keep the control.
                $label = trim(substr($label, strlen($head)));

                if ($label === '' || !str_contains($label, ' ')) {
                    continue;
                }
            }

            if (in_array($label, self::NOT_BUTTONS, true)) {
                continue;
            }

            $labels[] = $label;
        }

        return $labels;
    }

    private function adminSourcePath(): ?string
    {
        $explicit = getenv('KAAGAPAY_ADMIN_PATH');

        if (is_string($explicit) && $explicit !== '') {
            $path = rtrim($explicit, '/\\') . '/src';

            if (is_dir($path)) {
                return $path;
            }
        }

        foreach (self::ADMIN_PATHS as $candidate) {
            $path = dirname(__DIR__, 2) . $candidate;

            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Every .ts and .tsx file under the admin, as one string. */
    private function concatenatedSource(string $dir): string
    {
        $text = '';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (!preg_match('/\.tsx?$/', $file->getFilename())) {
                continue;
            }

            $text .= file_get_contents($file->getPathname());
        }

        return $text;
    }
}
