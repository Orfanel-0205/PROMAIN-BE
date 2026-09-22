<?php

namespace Tests\Unit;

use App\Support\LabTestCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The laboratory catalogue exists twice: here, and in the admin's
 * src/constants/labTests.ts. It has to, because the printed request is built
 * on the server while the form is built in the browser.
 *
 * Two copies of a clinical list is a standing hazard. A test added only to the
 * admin is tickable on screen, saves correctly, and is then silently missing
 * from the printed request the patient carries to the laboratory — the form
 * looks right at every point a human checks it. These tests exist so that
 * particular failure cannot ship quietly.
 */
class LabTestCatalogueTest extends TestCase
{
    /**
     * Where the admin copy might be, relative to the backend root.
     *
     * The two repositories are separate checkouts and are not reliably
     * siblings, so KAAGAPAY_ADMIN_PATH can point at the admin root instead.
     * Without either, the comparison skips: a developer who has only this
     * repository cannot be asked to fix a mismatch they cannot see.
     */
    private const ADMIN_CATALOGUE_PATHS = [
        '/../Rhu-admin-main-1/src/constants/labTests.ts',
        '/../rhu-admin-main/src/constants/labTests.ts',
        '/../../Rhu-admin-main-1/src/constants/labTests.ts',
        '/../../FINAL-SUBMISSION/Rhu-admin-main-1/src/constants/labTests.ts',
    ];

    /**
     * Values stored before this catalogue existed.
     *
     * A stored value is what prescriptions.lab_tests holds and what the printed
     * form matches on, so renaming one would blank that test on every
     * prescription already saved. They are listed literally here rather than
     * read from the catalogue: the point is to fail if the catalogue changes.
     */
    private const ORIGINAL_VALUES = [
        'laboratory' => [
            'CBC', 'Urinalysis', 'Fecalysis', 'FBS', 'HBA1C', 'B.U.A',
            'ALT', 'AST', 'Creatinine', 'B.U.N', 'Total Lipid Profile',
        ],
        'xray' => ['CXR - PA View', 'CXR - Apicolordotic View'],
        'ultrasound' => [
            'Whole Abdomen', 'Lower Abdomen', 'Upper Abdomen',
            'Prostate', 'HBT', 'KUB',
        ],
    ];

    #[Test]
    #[TestDox('every value stored before the catalogue existed is still offered')]
    public function original_values_are_preserved(): void
    {
        foreach (self::ORIGINAL_VALUES as $section => $expected) {
            $current = LabTestCatalogue::values($section);

            foreach ($expected as $value) {
                $this->assertContains(
                    $value,
                    $current,
                    "The stored value '{$value}' has gone from the {$section} "
                    . 'catalogue. Every prescription already saved with it would '
                    . 'stop ticking that box on the printed request. Add a new '
                    . 'entry instead of renaming this one.'
                );
            }
        }
    }

    #[Test]
    #[TestDox('no section offers the same stored value twice')]
    public function values_are_unique_within_a_section(): void
    {
        foreach (['laboratory', 'xray', 'ultrasound'] as $section) {
            $values = LabTestCatalogue::values($section);

            $this->assertSame(
                array_values(array_unique($values)),
                $values,
                "The {$section} catalogue lists a value twice, which would show "
                . 'the same test as two checkboxes that tick together.'
            );
        }
    }

    #[Test]
    #[TestDox('every test has a value and a label')]
    public function entries_are_complete(): void
    {
        foreach (['laboratory', 'xray', 'ultrasound'] as $section) {
            foreach (LabTestCatalogue::flat($section) as $test) {
                $this->assertNotSame('', trim($test['value']), 'Empty value in ' . $section);
                $this->assertNotSame('', trim($test['label']), 'Empty label in ' . $section);
            }
        }
    }

    #[Test]
    #[TestDox('selected values outside the catalogue are reported, not dropped')]
    public function unlisted_reports_retired_tests(): void
    {
        $unlisted = LabTestCatalogue::unlisted(
            'laboratory',
            ['CBC', 'Some Retired Test', '']
        );

        $this->assertSame(['Some Retired Test'], $unlisted);
    }

    #[Test]
    #[TestDox('the label falls back to the stored value for an unknown test')]
    public function label_falls_back_to_the_value(): void
    {
        $this->assertSame(
            'Complete Blood Count (CBC)',
            LabTestCatalogue::label('laboratory', 'CBC')
        );

        $this->assertSame(
            'Some Retired Test',
            LabTestCatalogue::label('laboratory', 'Some Retired Test')
        );
    }

    #[Test]
    #[TestDox('the admin catalogue offers exactly the same stored values')]
    public function admin_catalogue_matches(): void
    {
        $path = $this->adminCataloguePath();

        if ($path === null) {
            $this->markTestSkipped(
                'The admin repository was not found next to this one, so the '
                . 'two catalogues were not compared. Set KAAGAPAY_ADMIN_PATH '
                . 'to the admin checkout to run this. It is skipped in CI, '
                . 'which checks out only the backend -- so keeping the two '
                . 'files in step is still a matter of changing both.'
            );
        }

        $source = file_get_contents($path);

        // The admin file declares one group array per section.
        foreach ([
            'laboratory' => 'LABORATORY_GROUPS',
            'xray' => 'XRAY_GROUPS',
            'ultrasound' => 'ULTRASOUND_GROUPS',
        ] as $section => $constant) {
            $adminValues = $this->valuesFromTypeScript($source, $constant);

            $expected = LabTestCatalogue::values($section);

            sort($adminValues);
            sort($expected);

            $this->assertSame(
                $expected,
                $adminValues,
                "The {$section} catalogue differs between the backend and the "
                . 'admin. A test present in only one of them is either tickable '
                . 'and missing from the printed request, or printed and '
                . 'unselectable.'
            );
        }
    }

    private function adminCataloguePath(): ?string
    {
        $explicit = getenv('KAAGAPAY_ADMIN_PATH');

        if (is_string($explicit) && $explicit !== "") {
            $path = rtrim($explicit, '/\\') . '/src/constants/labTests.ts';

            if (is_file($path)) {
                return $path;
            }
        }

        foreach (self::ADMIN_CATALOGUE_PATHS as $candidate) {
            $path = base_path_guess() . $candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Pull the `value:` strings out of one exported group array.
     *
     * Deliberately a regex rather than anything cleverer: the file is a plain
     * data literal, and a parser would be more code than the thing it checks.
     *
     * @return array<int, string>
     */
    private function valuesFromTypeScript(string $source, string $constant): array
    {
        $start = strpos($source, 'export const ' . $constant);

        if ($start === false) {
            $this->fail("Could not find {$constant} in the admin catalogue.");
        }

        // Up to the next export, or the end of the file.
        $next = strpos($source, 'export ', $start + 10);
        $block = $next === false
            ? substr($source, $start)
            : substr($source, $start, $next - $start);

        preg_match_all('/value:\s*"((?:[^"\\\\]|\\\\.)*)"/', $block, $matches);

        return array_map(
            fn (string $value) => stripcslashes($value),
            $matches[1]
        );
    }
}

/**
 * base_path() needs the framework booted; this test does not otherwise, and
 * staying a plain unit test keeps it fast.
 */
function base_path_guess(): string
{
    return dirname(__DIR__, 2);
}
