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
        $notify = $this->method(
            $this->source('Services/Notification/NotificationService.php'),
            'public function notifyMhoSoapFinalized(',
            4500
        );

        // Not when an MHO finalized it, nor when a prescription already exists.
        $this->assertMatchesRegularExpression('/hasAnyRole\(\$mhoRoles\)\)\s*\{\s*return 0;/', $notify);
        $this->assertStringContainsString("->where('consultation_id', \$consultation->id)->exists()", $notify);

        // MHOs only, active ones, never the person who finalized it.
        $this->assertStringContainsString("\$mhoRoles = ['mho', 'mho_admin'];", $notify);
        $this->assertStringContainsString("->where('account_status', 'active')", $notify);

        // Once per consultation, and it opens that consultation.
        $this->assertStringContainsString('"soap_finalized:{$consultation->id}"', $notify);
        $this->assertStringContainsString('"/consultations/{$consultation->id}"', $notify);

        // A notification must never undo the save.
        $this->assertStringContainsString('catch (\Throwable $e)', $notify);
    }

    #[Test]
    #[TestDox('nurses, midwives and BHWs write, finish and scan SOAPs alongside doctors, MHOs and the Super Admin')]
    public function soap_writers(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../routes/api.php');
        $group = $this->method($routes, "Route::middleware('role:doctor,mho,super_admin,nurse,midwife,bhw')", 900);

        $this->assertStringContainsString("Route::put('/consultations/{id}/soap'", $group);
        $this->assertStringContainsString("Route::patch('/consultations/{id}/complete'", $group);
        $this->assertStringContainsString("Route::post('/consultations/{id}/scan-soap', [OcrController::class, 'scanSoap'])", $group);
        $this->assertStringContainsString("->middleware('throttle:20,1')", $group);
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
