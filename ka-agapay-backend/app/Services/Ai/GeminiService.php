<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey;

    private string $model = 'gemini-2.5-flash';

    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    private int   $maxRetries   = 3;
    private int   $baseDelayMs  = 1000;
    private float $jitterFactor = 0.2;

    private int $maxRequestsPerMinute = 8;

    private string $systemPrompt =
        "You are Ka-Agapay AI for RHU Malasiqui, Pangasinan. "
        . "Reply in the same language the user writes in (Tagalog, Ilocano, or English). "
        . "Give simple, warm healthcare guidance only. "
        . "Never diagnose diseases. "
        . "Recommend RHU or ER for serious symptoms. "
        . "Keep replies short and easy to understand (max 5 sentences).";

    private array $fallbackResponses = [

        'emergency' => [
            'keywords' => [
                'chest pain', 'sakit dibdib', 'hirap huminga',
                'hindi makahinga', 'stroke', 'dumudugo',
                'bleeding', 'unconscious', 'nawalan ng malay',
                'seizure', 'atake',
            ],
            'response' =>
                "⚠️⚠️⚠️ Posibleng emergency ito.⚠️⚠️⚠️ "
                . "Pumunta agad sa pinakamalapit na ospital o ER. "
                . "Huwag mag-antay — humingi ng tulong ngayon.",
        ],

        'appointment' => [
            'keywords' => [
                'appointment', 'book', 'schedule', 'check up',
                'konsultasyon', 'doktor', 'doctor', 'pumunta sa rhu',
            ],
            'response' =>
                "Maaari kang mag-book ng appointment gamit ang Appointment section ng app, "
                . "o personal na pumunta sa RHU Malasiqui mula Lunes hanggang Biyernes, 8AM–5PM. "
                . "Libre ang konsultasyon para sa lahat ng residente.",
        ],

        'services' => [
            'keywords' => [
                'services', 'serbisyo', 'gamot', 'medicine',
                'program', 'programa', 'free', 'libre', 'rhu',
            ],
            'response' =>
                  "Ang RHU Malasiqui ay nag-aalok ng mga sumusunod na serbisyo: "
        . "Konsulta at outpatient services, Maternal at prenatal/postnatal care, "
        . "Labor at newborn care para sa birthing clinics, Family planning services, "
        . "Child care at immunization, Nutrition services, Adolescent health services, "
        . "Dental services, TB-DOTS program, Morbid clinic consultations, "
        . "Minor surgery tulad ng tuli, wound suturing, at cyst excision, "
        . "Referral at ancillary services gaya ng laboratory, X-ray, ECG, pharmacy, at ambulance, "
        . "Pagkuha ng medical certificate at sanitary permit, "
        . "HIV/AIDS at STI counseling, Leprosy prevention, "
        . "Healthy lifestyle counseling at monitoring ng blood pressure at timbang, "
        . "Dengue, rabies, at communicable disease prevention, at Mental health services. "
        . "Bukas Lunes hanggang Biyernes, 8:00 AM hanggang 5:00 PM.",
        ],

        'lagnat' => [
            'keywords' => ['lagnat', 'fever', 'mainit katawan', 'mainit ang katawan'],
            'response' =>
                "Para sa lagnat: magpahinga, uminom ng maraming tubig, "
                . "at maaaring uminom ng paracetamol ayon sa tamang dosage. "
                . "Kung higit sa 38.5°C ang lagnat o may kasamang hirap huminga, "
                . "pumunta agad sa RHU.",
        ],

        'ulo' => [
            'keywords' => ['headache', 'sakit ng ulo', 'masakit ulo', 'masakit ang ulo'],
            'response' =>
                "Para sa sakit ng ulo: magpahinga sa tahimik na lugar, "
                . "uminom ng tubig, at maaaring uminom ng paracetamol. "
                . "Kung paulit-ulit, matindi, o may kasamang pagsusuka at lagnat, "
                . "pumunta sa RHU para sa pagsusuri.",
        ],

        'ubo' => [
            'keywords' => ['ubo', 'sipon', 'cough', 'cold', 'trangkaso', 'flu'],
            'response' =>
                "Para sa ubo at sipon: magpahinga nang sapat, "
                . "uminom ng maraming tubig at mainit na sabaw. "
                . "Kung ang ubo ay may dugo o mahirap huminga, "
                . "pumunta agad sa RHU. May libreng gamot ang RHU para dito.",
        ],

        'pagtatae' => [
            'keywords' => ['pagtatae', 'diarrhea', 'loose bowel', 'lbm'],
            'response' =>
                "Para sa pagtatae: uminom ng maraming tubig o oral rehydration solution (ORS) "
                . "para maiwasan ang dehydration. "
                . "Kung may dugo ang dumi o higit sa 3 araw na, pumunta agad sa RHU.",
        ],

        'sugat' => [
            'keywords' => ['sugat', 'wound'],
            'response' =>
                "Para sa sugat: linisin ng maayos gamit ang malinis na tubig at sabon, "
                . "at takpan ng malinis na tela o bandage. "
                . "Kung malalim, malaki, o hindi tumitigil ang dugo, "
                . "pumunta agad sa RHU o ospital.",
        ],

        'hilo' => [
            'keywords' => ['hilo', 'nahilo', 'dizzy', 'pagkahilo', 'nahihilo'],
            'response' =>
                "Para sa pagkahilo: umupo o humiga muna sa ligtas at preskong lugar. "
                . "Uminom ng tubig. Kung ang pagkahilo ay madalas o may kasamang panlalabo ng paningin, "
                . "pumunta agad sa RHU para makapagpa-checkup.",
        ],
    ];

    public function __construct()
    {
        $this->apiKey =
            config('services.google.gemini_api_key')
            ?: config('services.gemini.api_key')
            ?: env('GEMINI_API_KEY', '');

        if (empty($this->apiKey)) {
            Log::error('GeminiService: GEMINI_API_KEY is missing. Check .env and run: php artisan config:clear');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public entry point — called by ChatController
    // ─────────────────────────────────────────────────────────────────────────

    public function chat(string $message, array $history = []): string
    {
        $message = trim($message);

        if (empty($message)) {
            return "Pakitype po ang inyong tanong.";
        }

        // No API key → keyword fallback only
        if (empty($this->apiKey)) {
            Log::warning('GeminiService: no API key configured, using fallback');
            return $this->getFallbackResponse($message);
        }

        // ── Per-user/IP rate limiting ─────────────────────────────────────
        $identifier = auth()->id() ?: request()->ip() ?: 'guest';
        $rateKey    = 'gemini_rate_' . md5($identifier);

        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateKey, $this->maxRequestsPerMinute)) {
            Log::warning('GeminiService: rate limited', ['identifier' => $identifier]);
            return "Masyado pong maraming request. Pakisubukan muli pagkalipas ng 1 minuto.";
        }
        \Illuminate\Support\Facades\RateLimiter::hit($rateKey, 60);

        // ── Quick keyword match — answer instantly, skip Gemini ───────────
        $quick = $this->findQuickResponse($message);
        if ($quick !== null) {
            Log::info('GeminiService: quick keyword response served');
            return $quick;
        }

        // ── Normalized response cache ─────────────────────────────────────
        $normalized = preg_replace('/[^a-z0-9 ]/i', '', strtolower($message));
        $cacheKey   = 'gemini_response_' . md5($normalized);
        $cached     = Cache::get($cacheKey);
        if ($cached) {
            Log::info('GeminiService: cache hit for message');
            return $cached;
        }

        // ── Quota cooldown check ──────────────────────────────────────────
        if (Cache::has('gemini_quota_exhausted')) {
            Log::warning('GeminiService: quota cooldown is active');
            return $this->getFallbackResponse($message);
        }

        // ── Retry loop with exponential backoff ───────────────────────────
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $result = $this->callGeminiApi($message, $history);

                // Cache the successful response for 10 minutes
                Cache::put($cacheKey, $result, now()->addMinutes(10));

                return $result;

            } catch (QuotaExhaustedException $e) {
                // Set a 2-minute cooldown so subsequent requests skip Gemini
                Cache::put('gemini_quota_exhausted', true, now()->addMinutes(2));
                Log::warning('GeminiService: quota exhausted — cooldown set for 2 min');
                return $this->getFallbackResponse($message);

            } catch (GeminiException $e) {
                Log::warning('GeminiService: attempt failed', [
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                ]);

                if ($attempt < $this->maxRetries) {
                    usleep($this->calculateDelay($attempt) * 1000);
                }
            }
        }

        Log::error('GeminiService: all retries exhausted, falling back');
        return $this->getFallbackResponse($message);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Single Gemini API attempt
    // ─────────────────────────────────────────────────────────────────────────

    private function callGeminiApi(string $message, array $history): string
    {
        $contents = [];

        // Only last 4 messages to keep token usage low
        $history = array_slice($history, -4);

        foreach ($history as $msg) {
            $role = ($msg['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $text = trim($msg['content'] ?? '');
            if (!empty($text)) {
                $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
            }
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        Log::debug('GeminiService: Request Payload', [
            'contents_count' => count($contents),
            'last_message'   => $message,
        ]);

        $url = "{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}";

        // ✅ FIXED: ConnectionException (timeout/network) is now caught and
        // converted to a retryable GeminiException instead of crashing with 500
        try {
            $response = Http::timeout(15)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, [
                    'contents'          => $contents,
                    'systemInstruction' => [
                        'parts' => [['text' => $this->systemPrompt]],
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.7,
                        'maxOutputTokens' => 512,
                    ],
                    'safetySettings' => [
                        ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
                        ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
                        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                    ],
                ]);

        } catch (ConnectionException $e) {
            // Timeout or DNS failure — throw as retryable GeminiException
            Log::warning('GeminiService: connection timed out', [
                'model'   => $this->model,
                'message' => $e->getMessage(),
            ]);
            throw new GeminiException('Gemini connection timeout: ' . $e->getMessage());
        }

        // ── Non-2xx response ──────────────────────────────────────────────
        if (!$response->successful()) {
            $status     = $response->status();
            $body       = $response->json();
            $grpcStatus = $body['error']['status'] ?? '';

            Log::error('GeminiService: API returned error', [
                'status' => $status,
                'body'   => $body,
                'model'  => $this->model,
            ]);

            if ($status === 429 || $grpcStatus === 'RESOURCE_EXHAUSTED') {
                throw new QuotaExhaustedException('Gemini quota exhausted');
            }

            throw new GeminiException(
                "Gemini HTTP {$status}: " . ($body['error']['message'] ?? 'unknown error')
            );
        }

        Log::debug('GeminiService: Raw Response', [
            'body' => $response->json(),
        ]);

        // ── Safety block ──────────────────────────────────────────────────
        $finishReason = $response->json('candidates.0.finishReason');
        if ($finishReason === 'SAFETY') {
            return 'Paumanhin, hindi ko masagot ang tanong na iyon. Makipag-ugnayan sa RHU para sa tulong.';
        }

        // ── Extract text ──────────────────────────────────────────────────
        $parts = $response->json('candidates.0.content.parts');
        $text  = '';
        
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $text .= ($part['text'] ?? '');
            }
        }

        if (empty($text)) {
            Log::warning('GeminiService: empty text in API response', [
                'body' => $response->json(),
            ]);
            throw new GeminiException('Empty response from Gemini API');
        }

        Log::info('GeminiService: response received', [
            'model' => $this->model,
            'length' => strlen($text),
            'text_preview' => substr($text, 0, 50) . '...'
        ]);

        return trim($text);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function findQuickResponse(string $message): ?string
    {
        $lower = strtolower($message);
        foreach ($this->fallbackResponses as $config) {
            foreach ($config['keywords'] as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $config['response'];
                }
            }
        }
        return null;
    }

    private function getFallbackResponse(string $message): string
    {
        $quick = $this->findQuickResponse($message);
        if ($quick !== null) {
            return $quick;
        }

        return "Pasensya na, pansamantalang hindi available ang AI assistant. "
             . "Maaari kang pumunta sa RHU Malasiqui (Lunes–Biyernes, 8AM–5PM) "
             . "o gamitin ang app para mag-book ng appointment.";
    }

    /**
     * Exponential backoff with ±20% jitter.
     * attempt 1 → ~1000ms, attempt 2 → ~2000ms, attempt 3 → ~4000ms
     */
    private function calculateDelay(int $attempt): int
    {
        $base   = $this->baseDelayMs * (2 ** ($attempt - 1));
        $jitter = (int) ($base * $this->jitterFactor * (mt_rand(0, 200) / 100 - 1));
        return max(500, $base + $jitter);
    }
}

// ── Custom exceptions (keep in same file for simplicity) ─────────────────────
class GeminiException extends \RuntimeException {}
class QuotaExhaustedException extends GeminiException {}