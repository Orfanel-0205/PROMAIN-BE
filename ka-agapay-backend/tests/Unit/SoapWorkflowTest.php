<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * After a SOAP: telling the MHO, and scanning a paper SOAP form.
 *
 * The MHO is the RHU's doctor and releases prescriptions. When someone else
 * finalizes a SOAP, the MHO is told so the e-prescription does not wait for
 * someone to happen to open the record. And a paper SOAP form can be read in
 * by OCR -- as suggestions, by the same people who may edit a SOAP.
 */
class SoapWorkflowTest extends TestCase
{
    private const APP = __DIR__ . '/../../app';

    private function source(string $file): string
    {
        return (string) file_get_contents(self::APP . '/' . $file);
    }

    private function method(string $source, string $signature, int $length = 4000): string
    {
        $start = strpos($source, $signature);
        $this->assertNotFalse($start, "{$signature} is missing.");

        return (string) substr($source, (int) $start, $length);
    }

    #[Test]
    #[TestDox('finishing a consultation tells the MHO, after the save is committed')]
    public function completing_tells_the_mho(): void
    {
        $flow = $this->method(
            $this->source('Http/Controllers/Api/ConsultationController.php'),
            'private function syncCompletedConsultationFlow(',
            900
        );

        $this->assertStringContainsString('DB::afterCommit(', $flow, 'The MHO could be told about a save that rolled back.');
        $this->assertStringContainsString('->notifyMhoSoapFinalized(', $flow);
    }

    #[Test]
    #[TestDox('only the MHO is told, once, and not when there is nothing to ask for')]
    public function the_notification_rules(): void
    {
        $service = $this->source('Services/Notification/NotificationService.php');
        $notify = $this->method($service, 'public function notifyMhoSoapFinalized(', 2200);

        // Not when an MHO finalized it, nor when a prescription already exists.
        $this->assertMatchesRegularExpression('/hasAnyRole\(\$mhoRoles\)\)\s*\{\s*return 0;/', $notify);
        $this->assertStringContainsString("->where('consultation_id', \$consultation->id)->exists()", $notify);
        $this->assertStringContainsString("\$mhoRoles = ['mho', 'mho_admin'];", $notify);

        // Once per consultation; a notification must never undo the save.
        $this->assertStringContainsString('"soap_finalized:{$consultation->id}"', $notify);
        $this->assertStringContainsString('catch (\Throwable $e)', $notify);

        // "Sent for your review" goes the same way, with its own once-only key.
        $review = $this->method($service, 'public function notifyMhoSoapForReview(', 1800);
        $this->assertStringContainsString('"soap_for_review:{$consultation->id}"', $review);
        $this->assertStringContainsString('catch (\Throwable $e)', $review);

        // To active MHOs only, never the person who caused it, opening the record.
        $send = $this->method($service, 'private function notifyActiveMhos(', 1800);
        $this->assertStringContainsString("['mho', 'mho_admin']", $send);
        $this->assertStringContainsString("->where('account_status', 'active')", $send);
        $this->assertStringContainsString('!== (int) ($except?->user_id ?? 0)', $send);
        $this->assertStringContainsString('"/consultations/{$consultation->id}"', $send);
    }

    #[Test]
    #[TestDox('nurses, midwives and BHWs write, scan and send SOAPs; only the doctor completes them')]
    public function soap_writers(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../routes/api.php');
        $writers = $this->method($routes, "Route::middleware('role:doctor,mho,super_admin,nurse,midwife,bhw')", 900);

        $this->assertStringContainsString("Route::put('/consultations/{id}/soap'", $writers);
        $this->assertStringContainsString("Route::post('/consultations/{id}/send-for-review'", $writers);
        $this->assertStringContainsString("Route::post('/consultations/{id}/scan-soap', [OcrController::class, 'scanSoap'])", $writers);
        $this->assertStringContainsString("->middleware('throttle:20,1')", $writers);
        $this->assertStringNotContainsString('/complete', $writers, 'Nurses, midwives or BHWs can complete the record.');

        $doctors = $this->method($routes, "Route::middleware('role:doctor,mho,mho_admin,super_admin')", 400);
        $this->assertStringContainsString("Route::patch('/consultations/{id}/complete'", $doctors);
    }

    #[Test]
    #[TestDox("the doctor's sections of the ITR are the doctor's: nurses can neither change them nor have them filled for them")]
    public function the_doctors_part(): void
    {
        $controller = $this->source('Http/Controllers/Api/ConsultationController.php');

        $this->assertStringContainsString(
            "private const DOCTOR_FIELDS = ['diagnosis', 'treatment', 'treatment_plan', 'prescribed_drugs'];",
            $controller
        );

        // Checked on every way a SOAP is written by the staff.
        foreach (['public function updateSoap(', 'public function sendForReview('] as $action) {
            $this->assertStringContainsString(
                '$this->assertNotWritingDoctorPart($request, $consultation,',
                $this->method($controller, $action, 1800),
                "{$action} lets a nurse write the doctor's sections."
            );
        }

        // A nurse's assessment is not copied into the diagnosis.
        $build = $this->method($controller, 'private function buildSoapUpdates(', 1600);
        $guard = strpos($build, 'if ($this->isDoctorSide($request)) {');
        $copy = strpos($build, "\$updates['diagnosis'] = \$updates['assessment'];");

        $this->assertNotFalse($guard);
        $this->assertNotFalse($copy);
        $this->assertLessThan($copy, $guard, 'The assessment is copied into the diagnosis for everyone.');
    }

    #[Test]
    #[TestDox('a SOAP is written only by its own RHU, an MHO or the Super Admin')]
    public function soap_writes_stay_in_their_rhu(): void
    {
        $controller = $this->source('Http/Controllers/Api/ConsultationController.php');

        foreach (['public function updateSoap(', 'public function complete('] as $action) {
            $body = $this->method($controller, $action, 400);
            $this->assertStringContainsString('$this->assertCanWriteSoap($request, $consultation);', $body, "{$action} skips the RHU check.");
        }

        $scan = $this->method($this->source('Http/Controllers/Api/OcrController.php'), 'public function scanSoap(', 2500);
        $this->assertStringContainsString('Rhu::canAccessRhu(', $scan, 'The SOAP scan skips the RHU check.');

        // Issuing a prescription stays with Doctor / MHO / Super Admin.
        $this->assertStringNotContainsString("'nurse'", (string) substr(
            $this->source('Http/Controllers/Api/PrescriptionController.php'),
            (int) strpos($this->source('Http/Controllers/Api/PrescriptionController.php'), 'private const PRESCRIBER_ROLES'),
            200
        ));
    }

    #[Test]
    #[TestDox('the scanned photo is read and discarded, and a completed SOAP is not touched')]
    public function scan_keeps_nothing(): void
    {
        $scan = $this->method(
            $this->source('Http/Controllers/Api/OcrController.php'),
            'public function scanSoap(',
            3500
        );

        $scan = substr($scan, 0, (int) strpos($scan, 'public function scanPrescription('));

        $this->assertStringNotContainsString('SensitiveFiles::store', $scan, 'Scanned SOAP photos are being kept.');
        $this->assertStringNotContainsString('->update(', $scan, 'The scan saves into the consultation; it should only suggest.');
        $this->assertStringContainsString("'completed'", $scan);

        // The audit log records what was found, never the patient's notes.
        $this->assertStringNotContainsString("'text' => \$text,\n        ], \$request)", $scan);
    }
}
