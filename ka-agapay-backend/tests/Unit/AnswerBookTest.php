<?php

namespace Tests\Unit;

use App\Services\Ai\AnswerBook;
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
