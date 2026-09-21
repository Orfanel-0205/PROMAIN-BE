<?php
// tests/Feature/Security/ExportMaskingTest.php
//
// Privacy mode has to cover the file that actually carries identities.
//
// The Reports screen offered a privacy toggle that masked the follow-up and
// staff-workload downloads, described in general terms: mask personal data,
// aggregate exports unaffected. Read plainly, that promises masking wherever
// there are names.
//
// The Diagnosis + ITR export was the one it did not touch, and it is the one
// that matters: full name, PhilHealth number, address, mobile number,
// birthdate, guardian details and the SOAP notes -- a complete patient record.
// Staff could tick the box, download it, and hand the file to someone outside
// the RHU believing it was masked.
//
// These tests hold the masking itself. They are deliberately about the shape
// of the output rather than one column list, because the failure to guard
// against is a new identifying column being added later and quietly exported
// in the clear.

namespace Tests\Feature\Security;

use App\Http\Controllers\Api\ReportController;
use ReflectionMethod;
use Tests\TestCase;

class ExportMaskingTest extends TestCase
{
    private function mask(string $value): string
    {
        $method = new ReflectionMethod(ReportController::class, 'maskValue');
        $method->setAccessible(true);

        return $method->invoke(
            (new \ReflectionClass(ReportController::class))->newInstanceWithoutConstructor(),
            $value
        );
    }

    /** @param array<string, callable> $columns */
    private function maskColumns(array $columns): array
    {
        $method = new ReflectionMethod(ReportController::class, 'maskIdentifyingColumns');
        $method->setAccessible(true);

        return $method->invoke(
            (new \ReflectionClass(ReportController::class))->newInstanceWithoutConstructor(),
            $columns
        );
    }

    public function test_a_name_keeps_only_its_first_letter(): void
    {
        $this->assertSame('C*********************', $this->mask('Clifford Psalm Orfanel'));
    }

    public function test_a_mobile_number_still_reads_as_a_mobile_number(): void
    {
        // Keeping the 09 lets staff see the column holds a phone number
        // without the number being recoverable.
        $this->assertSame('09*********', $this->mask('09171234567'));
    }

    public function test_a_birthdate_keeps_only_the_year(): void
    {
        // A date of birth identifies a person on its own. The year is the part
        // the age bands in a report are actually built from.
        $this->assertSame('1990-**-**', $this->mask('1990-05-14'));
    }

    public function test_a_philhealth_number_is_not_readable(): void
    {
        $masked = $this->mask('PH-1234-5678');

        $this->assertStringStartsWith('P', $masked);
        $this->assertStringNotContainsString('1234', $masked);
    }

    public function test_an_empty_value_stays_empty(): void
    {
        // A column of asterisks where there was no data would look like
        // information that had been withheld rather than never collected.
        $this->assertSame('', $this->mask(''));
    }

    public function test_every_identifying_column_is_masked_and_clinical_ones_are_not(): void
    {
        $columns = [
            'ITR: Patient Full Name' => fn (array $r) => 'Clifford Orfanel',
            'ITR: PhilHealth Number / ID' => fn (array $r) => 'PH-1234-5678',
            'ITR: Mobile Number' => fn (array $r) => '09171234567',
            'ITR: Address' => fn (array $r) => '123 Real Street',
            'ITR: Birthdate' => fn (array $r) => '1990-05-14',
            'ITR: Guardian Contact' => fn (array $r) => '09181234567',
            'SOAP: Diagnosis' => fn (array $r) => 'Acute Respiratory Infection',
            'Session: Consultation ID' => fn (array $r) => '38',
        ];

        $masked = $this->maskColumns($columns);
        $row = [];

        foreach (['ITR: Patient Full Name', 'ITR: PhilHealth Number / ID', 'ITR: Mobile Number',
                  'ITR: Address', 'ITR: Birthdate', 'ITR: Guardian Contact'] as $label) {
            $value = $masked[$label]($row);

            $this->assertStringContainsString('*', $value, "{$label} was exported in the clear");
        }

        // The clinical and reference columns are the reason the file exists and
        // identify nobody on their own; masking them would make it useless.
        $this->assertSame('Acute Respiratory Infection', $masked['SOAP: Diagnosis']($row));
        $this->assertSame('38', $masked['Session: Consultation ID']($row));
    }

    public function test_a_new_column_with_a_name_in_it_is_masked_by_default(): void
    {
        // The realistic future failure: somebody adds a column and nobody
        // remembers this file has a privacy mode. Matching on the label means
        // it fails towards masking rather than towards disclosure.
        $masked = $this->maskColumns([
            'ITR: Spouse Name' => fn (array $r) => 'Maria Santos',
            'ITR: Emergency Contact' => fn (array $r) => '09991234567',
        ]);

        $this->assertStringContainsString('*', $masked['ITR: Spouse Name']([]));
        $this->assertStringContainsString('*', $masked['ITR: Emergency Contact']([]));
    }
}
