<?php

namespace Tests\Unit;

use App\Services\Ai\GeminiService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Where a resident's message goes before any model is called.
 *
 * Three outcomes are possible, and getting them wrong has very different
 * costs. An emergency phrase must return the fixed warning immediately, so a
 * slow or unreachable model cannot delay the one reply that is time-critical.
 * A health question must return null, meaning "ask the model", because the
 * canned navigation answers cannot help with a headache. Only an app question
 * should get a canned answer.
 *
 * The routing is tested rather than the wording: what the model says is not
 * deterministic, but which path a sentence takes is, and that is where the
 * failures that matter live.
 */
class ResidentAssistantRoutingTest extends TestCase
{
    private function route(string $message): ?string
    {
        $method = new ReflectionMethod(GeminiService::class, 'ruleBasedResponse');
        $method->setAccessible(true);

        return $method->invoke(app(GeminiService::class), $message, 'resident');
    }

    public static function emergencyPhrases(): array
    {
        return [
            'english chest pain' => ['i have chest pain since this morning'],
            'english breathing' => ['my father has difficulty breathing'],
            'english worst headache' => ['this is the worst headache of my life'],
            'english stiff neck' => ['fever and a stiff neck since last night'],
            'tagalog chest' => ['masakit ang dibdib ko'],
            'tagalog breathing' => ['hirap huminga ang anak ko'],
            'tagalog fainting' => ['nawalan ng malay si nanay'],
            'pangasinan chest' => ['ansakit so pagew ko'],
        ];
    }

    #[Test]
    #[DataProvider('emergencyPhrases')]
    #[TestDox('an emergency phrase is answered without waiting for the model')]
    public function emergencies_short_circuit(string $message): void
    {
        $reply = $this->route($message);

        $this->assertNotNull(
            $reply,
            "'{$message}' reached the model instead of returning the fixed "
            . 'emergency warning. If the model is slow or down, this reply '
            . 'would be delayed or lost.'
        );

        $this->assertStringContainsString('EMERGENCY', $reply);

        // Understandable without first changing a language setting.
        $this->assertStringContainsString('ospital', $reply);
        $this->assertStringContainsString('hospital', $reply);
    }

    public static function healthQuestions(): array
    {
        return [
            'the question that prompted this' => ['why does my head hurts after i woke up and give me some home remedies'],
            'fluids -- used to match the ID rule' => ['i have a headache, should i drink more fluids?'],
            'plain cough' => ['i have a cough and colds for three days'],
            'stomach' => ['my stomach hurts after eating'],
            'tagalog headache' => ['bakit masakit ang ulo ko pagkagising'],
            'tagalog fever' => ['may lagnat ang anak ko, ano ang gagawin ko'],
            'tagalog home remedy' => ['may lunas ba sa bahay para sa ubo'],
            'pangasinan' => ['ansakit so ulo ko'],
            'toothache' => ['my tooth pain will not stop'],
            'period pain' => ['period pain every month, is that normal'],
        ];
    }

    #[Test]
    #[DataProvider('healthQuestions')]
    #[TestDox('a health question is sent to the model, not answered from the keyword rules')]
    public function health_questions_reach_the_model(string $message): void
    {
        $this->assertNull(
            $this->route($message),
            "'{$message}' was answered by a canned navigation rule. A resident "
            . 'asking about their body would get a click-here walkthrough, '
            . 'which is the reply that makes them stop using the assistant.'
        );
    }

    public static function appQuestions(): array
    {
        return [
            'booking' => ['how do i book an appointment', 'Appointments'],
            'records' => ['where can i see my records', 'Records'],
            'id upload' => ['how do i upload my id for verification', 'ID Verification'],
        ];
    }

    #[Test]
    #[DataProvider('appQuestions')]
    #[TestDox('an app question still gets its direct answer')]
    public function app_questions_stay_canned(string $message, string $expected): void
    {
        $reply = $this->route($message);

        $this->assertNotNull(
            $reply,
            "'{$message}' is an app question and should be answered directly "
            . 'rather than costing a model call.'
        );

        $this->assertStringContainsString($expected, $reply);
    }

    #[Test]
    #[TestDox('short keywords no longer match the middle of ordinary words')]
    public function short_keywords_are_whole_words(): void
    {
        $method = new ReflectionMethod(GeminiService::class, 'containsAnyWord');
        $method->setAccessible(true);

        $service = app(GeminiService::class);

        // The bug this replaced: 'id' inside "fluids".
        $this->assertFalse($method->invoke($service, 'drink more fluids', ['id']));
        $this->assertFalse($method->invoke($service, 'i said okay', ['id']));
        $this->assertFalse($method->invoke($service, 'that is a good idea', ['id']));

        $this->assertTrue($method->invoke($service, 'upload my id please', ['id']));
        $this->assertTrue($method->invoke($service, 'my id, is it ok?', ['id']));
    }
}
