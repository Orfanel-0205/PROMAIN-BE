<?php

namespace Tests\Unit;

use App\Services\Consultation\SoapScanParser;
use App\Support\LabTestCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Reading a photographed paper SOAP form into suggestions.
 *
 * The text below is shaped like OCR output from a printed RHU form: a header
 * nobody wants in the SOAP, single-letter headings, vitals on one line,
 * clinicians' shorthand for lab tests.
 */
class SoapScanParserTest extends TestCase
{
    private const FORM = <<<'TXT'
        RHU MALASIQUI    Date: 10/05/2026
        Name: Juan Dela Cruz    Age: 34
        S: Fever and cough x 3 days
        O: BP 120/80  T 38.2 C  PR 96  RR 22  SpO2 97%  Wt 58 kg
        chest: crackles R lower lung, upper abdomen soft
        A: Community acquired pneumonia
        P: CBC, U/A, CXR PA
        UTZ whole abdomen if no improvement
        Rx: Amoxicillin 500mg TID x 7 days
        TXT;

    #[Test]
    #[TestDox('the SOAP sections are read from their headings, and the form header is left out')]
    public function sections(): void
    {
        $fields = SoapScanParser::parse(self::FORM)['fields'];

        $this->assertSame('Fever and cough x 3 days', $fields['subjective']);
        $this->assertStringStartsWith('BP 120/80', $fields['objective']);
        $this->assertSame('Community acquired pneumonia', $fields['assessment']);
        $this->assertStringStartsWith('CBC, U/A, CXR PA', $fields['plan']);
        $this->assertSame('Amoxicillin 500mg TID x 7 days', $fields['treatment']);

        foreach ($fields as $text) {
            $this->assertStringNotContainsString('Juan Dela Cruz', $text, 'The form header leaked into the SOAP.');
        }
    }

    #[Test]
    #[TestDox('a single letter is a heading only with a colon')]
    public function single_letters_need_a_colon(): void
    {
        $fields = SoapScanParser::parse("Subjective: headache\nA patient with no fever\nP: rest")['fields'];

        // "A patient..." is part of the subjective, not an assessment heading.
        $this->assertStringContainsString('A patient with no fever', $fields['subjective']);
        $this->assertArrayNotHasKey('assessment', $fields);
    }

    #[Test]
    #[TestDox('vital signs go to the SOAP page fields, in the units it keeps')]
    public function vitals(): void
    {
        $this->assertSame([
            'blood_pressure' => '120/80',
            'temperature_celsius' => '38.2',
            'heart_rate' => '96',
            'spo2' => '97',
            'weight' => '58',
            'vital_signs' => 'RR 22',
        ], SoapScanParser::parse(self::FORM)['vitals']);

        // Pounds become kilograms; impossible readings are dropped.
        $other = SoapScanParser::parse("O: Weight 132 lbs  Temp 98 F  HR 600")['vitals'];
        $this->assertSame('59.9', $other['weight']);
        $this->assertArrayNotHasKey('temperature_celsius', $other);
        $this->assertArrayNotHasKey('heart_rate', $other);
    }

    #[Test]
    #[TestDox('lab tests are matched to the catalogue, shorthand included')]
    public function lab_tests(): void
    {
        $labs = SoapScanParser::parse(self::FORM)['lab_tests'];

        $this->assertSame(['CBC', 'Urinalysis'], $labs['laboratory']);
        $this->assertSame(['CXR - PA View'], $labs['xray']);
        $this->assertSame(['Whole Abdomen'], $labs['ultrasound']);

        // Every value is one the request form can tick.
        foreach (['laboratory', 'xray', 'ultrasound'] as $section) {
            foreach ($labs[$section] as $value) {
                $this->assertContains($value, LabTestCatalogue::values($section));
            }
        }
    }

    #[Test]
    #[TestDox('body parts in the exam notes are not taken for ultrasound requests')]
    public function exam_notes_are_not_requests(): void
    {
        $labs = SoapScanParser::parse("O: upper abdomen soft, prostate not enlarged\nP: SGPT, RBS\nupper abdomen recheck")['lab_tests'];

        $this->assertSame([], $labs['ultrasound']);
        $this->assertSame(['ALT', 'Random Blood Sugar'], $labs['laboratory']);
    }

    #[Test]
    #[TestDox('text with no headings gives no fields, rather than guesses')]
    public function no_headings(): void
    {
        $parsed = SoapScanParser::parse("just some text\nthat is not a SOAP form");

        $this->assertSame([], $parsed['fields']);
    }
}
