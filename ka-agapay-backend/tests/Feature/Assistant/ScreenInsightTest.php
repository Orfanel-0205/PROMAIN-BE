<?php
// tests/Feature/Assistant/ScreenInsightTest.php
//
// The assistant can see the figures on the screen being asked about.
//
// Staff ask "what does this mean?" while looking at a chart. An assistant that
// cannot see the chart answers in generalities, which is how it earned the
// reputation of being useless for anything beyond where-do-I-click.
//
// The dashboard sends the figures a page has already drawn -- totals, the
// period in view, barangays ranked by risk. Never a patient, never a record,
// never a picture of the screen. These tests hold both halves of that: the
// figures do reach the model, and the instructions that come with them say to
// explain, to flag what is wrong, and to advise -- not to restate what staff
// can already see, and not to invent what is not there.

namespace Tests\Feature\Assistant;

use App\Services\Ai\GeminiService;
use ReflectionMethod;
use Tests\TestCase;

class ScreenInsightTest extends TestCase
{
    private const SCREEN = "Screen: Analytics\n"
        . "Showing: RHU 1, 2026-09-01 to 2026-09-21\n"
        . "Figures currently on screen:\n"
        . "- Patients: 1284\n"
        . "- Top Diagnosis: Acute Respiratory Infection\n"
        . "Also shown:\n"
        . "- Barangay Tolonguat: risk high (score 82)";

    private function prompt(string $message, array $context): string
    {
        $method = new ReflectionMethod(GeminiService::class, 'buildUserPrompt');
        $method->setAccessible(true);

        return $method->invoke(new GeminiService(), $message, 'staff', $context);
    }

    public function test_the_figures_on_screen_are_given_to_the_model(): void
    {
        $prompt = $this->prompt('what does this mean?', ['screen' => self::SCREEN]);

        // Every line matters: a figure the model cannot see is a figure it
        // either omits or, worse, invents.
        $this->assertStringContainsString('Patients: 1284', $prompt);
        $this->assertStringContainsString('Acute Respiratory Infection', $prompt);
        $this->assertStringContainsString('Barangay Tolonguat: risk high (score 82)', $prompt);
        $this->assertStringContainsString('RHU 1, 2026-09-01 to 2026-09-21', $prompt);
    }

    public function test_it_is_told_to_explain_then_flag_then_advise(): void
    {
        $prompt = $this->prompt('explain this chart', ['screen' => self::SCREEN]);

        $this->assertStringContainsString('WHAT THE STAFF MEMBER IS LOOKING AT RIGHT NOW', $prompt);
        $this->assertStringContainsString('plain words', $prompt);
        $this->assertStringContainsString('looks wrong', $prompt);
        $this->assertStringContainsString('should actually do about it', $prompt);
    }

    public function test_it_is_forbidden_from_inventing_figures(): void
    {
        $prompt = $this->prompt('is this bad?', ['screen' => self::SCREEN]);

        // The failure that would matter most in a health service is a made-up
        // trend stated confidently, so the instruction is explicit.
        $this->assertStringContainsString('Never invent a number', $prompt);
        $this->assertStringContainsString('ONLY the figures listed above', $prompt);
    }

    public function test_totals_are_never_treated_as_one_patient(): void
    {
        $prompt = $this->prompt('what should we do?', ['screen' => self::SCREEN]);

        $this->assertStringContainsString('These are totals, not patients', $prompt);
    }

    public function test_a_screen_with_nothing_to_say_adds_nothing(): void
    {
        $prompt = $this->prompt('how do I add a patient?', []);

        // A question asked away from a chart must not carry a stale screen, and
        // must not spend the prompt on instructions for reading one.
        $this->assertStringNotContainsString('WHAT THE STAFF MEMBER IS LOOKING AT', $prompt);
    }

    public function test_the_assistant_is_not_told_it_is_blind_to_its_own_data(): void
    {
        $method = new ReflectionMethod(GeminiService::class, 'systemPrompt');
        $method->setAccessible(true);

        $system = $method->invoke(new GeminiService(), 'staff');

        // It has lookup tools and now the screen too. The old instruction that
        // it had no access to live data made it refuse figures it was holding.
        $this->assertStringNotContainsString('You have NO access to live system data', $system);
        $this->assertStringContainsString('WHAT YOU CAN AND CANNOT SEE', $system);
        $this->assertStringContainsString('CANNOT see individual records', $system);
    }

    public function test_the_clinical_line_is_stated_and_absolute(): void
    {
        $method = new ReflectionMethod(GeminiService::class, 'systemPrompt');
        $method->setAccessible(true);

        $system = $method->invoke(new GeminiService(), 'staff');

        // Auto-fill covers administrative work only. A dose sitting ready in a
        // form is one distracted click from reaching a patient.
        $this->assertStringContainsString('THE CLINICAL LINE', $system);
        $this->assertStringContainsString('a dose, a frequency, a duration', $system);
        $this->assertStringContainsString('ADMINISTRATIVE work', $system);
    }
}
