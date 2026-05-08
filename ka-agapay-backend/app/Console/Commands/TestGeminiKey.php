<?php
// app/Console/Commands/TestGeminiKey.php
// Usage: php artisan gemini:test

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestGeminiKey extends Command
{
    protected $signature   = 'gemini:test';
    protected $description = 'Test your Gemini API key and diagnose quota/auth issues';

    public function handle(): int
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  Ka-Agapay — Gemini API Tester');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // 1. Check key presence
        $key = config('services.google.gemini_api_key')
            ?: config('services.gemini.api_key')
            ?: env('GEMINI_API_KEY', '');

        if (empty($key)) {
            $this->error('❌ GEMINI_API_KEY is empty!');
            $this->line('   Add it to your .env: GEMINI_API_KEY=AIza...');
            $this->line('   Then run: php artisan config:clear');
            return Command::FAILURE;
        }

        $this->info('✅ Key found: ' . substr($key, 0, 8) . '...' . substr($key, -4));

        // 2. Send a minimal test request
        $this->line('');
        $this->line('Sending test request to Gemini...');

        $model    = 'gemini-2.5-flash';
        $url      = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

        $response = Http::timeout(15)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => 'Say "OK" only.']]]
                ],
                'generationConfig' => ['maxOutputTokens' => 10],
            ]);

        $status = $response->status();
        $body   = $response->json();

        $this->line("HTTP Status: {$status}");

        match (true) {
            $status === 200 => $this->printSuccess($body),
            $status === 400 => $this->printBadRequest($body),
            $status === 401 => $this->printUnauthorized(),
            $status === 403 => $this->printForbidden($body),
            $status === 429 => $this->printQuotaExhausted($body),
            $status >= 500  => $this->printServerError($status),
            default         => $this->error("Unexpected status {$status}: " . json_encode($body)),
        };

        return $status === 200 ? Command::SUCCESS : Command::FAILURE;
    }

    private function printSuccess(array $body): void
    {
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '(empty)';
        $this->info('');
        $this->info('✅ SUCCESS! Gemini responded: "' . trim($text) . '"');
        $this->info('   Your API key is working correctly.');
        $this->line('');
        $this->line('If the chatbot still fails, clear the app cache:');
        $this->line('  php artisan cache:clear');
        $this->line('  php artisan config:clear');
    }

    private function printBadRequest(array $body): void
    {
        $this->error('❌ 400 Bad Request');
        $msg = $body['error']['message'] ?? json_encode($body);
        $this->line("   Message: {$msg}");
        $this->line('   Check: model name, request payload format.');
    }

    private function printUnauthorized(): void
    {
        $this->error('❌ 401 Unauthorized — Invalid API key!');
        $this->line('   → Make sure you copied the full key from Google AI Studio.');
        $this->line('   → Check for extra spaces in .env');
        $this->line('   → Run: php artisan config:clear');
    }

    private function printForbidden(array $body): void
    {
        $this->error('❌ 403 Forbidden');
        $msg = $body['error']['message'] ?? json_encode($body);
        $this->line("   Message: {$msg}");
        $this->line('   → Enable the Generative Language API in Google Cloud Console.');
        $this->line('   → Visit: https://console.cloud.google.com/apis/library');
    }

    private function printQuotaExhausted(array $body): void
    {
        $this->error('❌ 429 Quota Exhausted — This is your current problem!');
        $this->line('');
        $this->warn('The free tier limits are:');
        $this->line('  • gemini-2.0-flash: 15 requests/minute, 1,500/day');
        $this->line('');
        $this->warn('Solutions:');
        $this->line('  1. Wait 1-2 minutes and try again (most common fix)');
        $this->line('  2. Add billing to your Google Cloud project for higher limits');
        $this->line('  3. The new GeminiService.php handles this automatically with:');
        $this->line('     - Exponential backoff retry (3 attempts)');
        $this->line('     - 2-minute quota cooldown cache');
        $this->line('     - Keyword-based fallback responses');
    }

    private function printServerError(int $status): void
    {
        $this->error("❌ {$status} Server Error — Gemini is having issues.");
        $this->line('   → Check status: https://status.cloud.google.com');
        $this->line('   → The new GeminiService.php will retry automatically.');
    }
}