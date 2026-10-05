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
        // "Rx:" is the prescription: the SOAP page's Prescribed Drug/s field.
        $this->assertSame('Amoxicillin 500mg TID x 7 days', $fields['prescribed_drugs']);

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
    #[TestDox("the RHU's own Individual Treatment Record is read")]
    public function the_rhu_itr_form(): void
    {
        // Shaped like OCR of the Malasiqui MHO's ITR: printed labels, the
        // blanks staff fill, "S-" headings, and the doctor's sections below.
        $form = <<<'TXT'
            Municipality of Malasiqui
            OFFICE OF THE MUNICIPAL HEALTH OFFICER
            Individual Treatment Record
            Consultation Date: 10/05/2026            Client Type: M  D
            Full Name: Sample, Test Demo
            Age: 34  Bdate: 01/01/1990  Gender: M  Civil Status: S
            V/S: Ht: 165  Wt: 61  BMI: 22.4  Temp: 38.4 °C  BP: 110/70 mm/Hg  SpO2: 96  HR: 98  PR: 98  RR: 22
            Blood Type: O  Visual Acuity: L 20/20  R 20/30
            Personal/Social History
            Y N :Smoking   Y N :Alcohol Intake
            Past Medical History
            Y N :Hypertension   Y N :Tuberculosis
            General Survey
            Awake and Alert   Altered Sensorium
            Chief Complaint
            S- Fever and cough for 3 days
            O- Crackles right lower lung
            A- Ineffective airway clearance
            P- Increase fluids, monitor temperature, refer to MHO
            Remarks & Diagnosis
            Community acquired pneumonia. Request CBC, CXR PA
            Prescribe Drug/s
            Amoxicillin 500mg TID x 7 days
            TXT;

        $parsed = SoapScanParser::parse($form);

        $this->assertSame('Fever and cough for 3 days', $parsed['fields']['subjective']);
        $this->assertSame('Crackles right lower lung', $parsed['fields']['objective']);
        $this->assertSame('Ineffective airway clearance', $parsed['fields']['assessment']);
        $this->assertSame('Increase fluids, monitor temperature, refer to MHO', $parsed['fields']['plan']);
        $this->assertSame('Community acquired pneumonia. Request CBC, CXR PA', $parsed['fields']['diagnosis']);
        $this->assertSame('Amoxicillin 500mg TID x 7 days', $parsed['fields']['prescribed_drugs']);

        // The patient details and checklists above "Chief Complaint" stay out.
        foreach ($parsed['fields'] as $text) {
            $this->assertStringNotContainsString('Smoking', $text);
            $this->assertStringNotContainsString('Sample, Test', $text);
        }

        $this->assertSame('110/70', $parsed['vitals']['blood_pressure']);
        $this->assertSame('38.4', $parsed['vitals']['temperature_celsius']);
        $this->assertSame('22.4', $parsed['vitals']['bmi']);
        $this->assertSame('20/20', $parsed['vitals']['visual_acuity_left']);
        $this->assertSame('20/30', $parsed['vitals']['visual_acuity_right']);

        // Lab requests written in the doctor's remarks are found.
        $this->assertSame(['CBC'], $parsed['lab_tests']['laboratory']);
        $this->assertSame(['CXR - PA View'], $parsed['lab_tests']['xray']);
    }

    #[Test]
    #[TestDox('"A-fib" in a note is not an Assessment heading')]
    public function a_dash_needs_a_space(): void
    {
        $fields = SoapScanParser::parse("S- palpitations\nA-fib on ECG last year")['fields'];

        $this->assertArrayNotHasKey('assessment', $fields);
        $this->assertStringContainsString('A-fib on ECG last year', $fields['subjective']);
    }

    #[Test]
    #[TestDox('text with no headings gives no fields, rather than guesses')]
    public function no_headings(): void
    {
        $parsed = SoapScanParser::parse("just some text\nthat is not a SOAP form");

        $this->assertSame([], $parsed['fields']);
    }
}
