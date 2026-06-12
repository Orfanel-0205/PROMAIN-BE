<?php
// app/Services/Ai/GeminiService.php

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

    public function __construct()
    {
        $this->apiKey = (string) (
            config('services.google.gemini_api_key')
            ?: env('GEMINI_API_KEY', '')
        );
    }

    /**
     * Shared chatbot for mobile residents and RHU admin/staff.
     */
    public function chat(
        string $message,
        array $history = [],
        string $audience = 'resident',
        array $context = []
    ): string {
        $message = trim($message);

        if ($message === '') {
            return $this->fallbackResponse($message, $audience);
        }

        $ruleBased = $this->ruleBasedResponse($message, $audience);

        if ($ruleBased !== null) {
            return $ruleBased;
        }

        if ($this->apiKey === '') {
            Log::warning('[GeminiService] Missing GEMINI_API_KEY, using fallback.');
            return $this->fallbackResponse($message, $audience);
        }

        $cooldownKey = 'gemini_cooldown';

        if (Cache::get($cooldownKey)) {
            return $this->fallbackResponse($message, $audience);
        }

        try {
            return $this->callGeminiApi($message, $history, $audience, $context);
        } catch (ConnectionException $e) {
            Log::warning('[GeminiService] Connection failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackResponse($message, $audience);
        } catch (\Throwable $e) {
            Log::error('[GeminiService] Unexpected error', [
                'class' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackResponse($message, $audience);
        }
    }

    private function callGeminiApi(
        string $message,
        array $history,
        string $audience,
        array $context
    ): string {
        $history = array_slice($history, -8);

        $contents = [];

        foreach ($history as $item) {
            $role = ($item['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $text = trim((string) ($item['content'] ?? ''));

            if ($text !== '') {
                $contents[] = [
                    'role' => $role,
                    'parts' => [
                        ['text' => $text],
                    ],
                ];
            }
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [
                [
                    'text' => $this->buildUserPrompt($message, $audience, $context),
                ],
            ],
        ];

        $url = "{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}";

        $response = Http::timeout(20)
            ->retry(2, 700)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post($url, [
                'contents' => $contents,
                'systemInstruction' => [
                    'parts' => [
                        [
                            'text' => $this->systemPrompt($audience),
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.45,
                    'maxOutputTokens' => 700,
                ],
                'safetySettings' => [
                    [
                        'category' => 'HARM_CATEGORY_HARASSMENT',
                        'threshold' => 'BLOCK_ONLY_HIGH',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_HATE_SPEECH',
                        'threshold' => 'BLOCK_ONLY_HIGH',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                        'threshold' => 'BLOCK_ONLY_HIGH',
                    ],
                    [
                        'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                        'threshold' => 'BLOCK_ONLY_HIGH',
                    ],
                ],
            ]);

        if ($response->status() === 429) {
            Cache::put('gemini_cooldown', true, now()->addMinutes(2));
            return $this->fallbackResponse($message, $audience);
        }

        if (!$response->successful()) {
            Log::warning('[GeminiService] Gemini request failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 1000),
            ]);

            return $this->fallbackResponse($message, $audience);
        }

        $payload = $response->json();

        $reply = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!is_string($reply) || trim($reply) === '') {
            return $this->fallbackResponse($message, $audience);
        }

        return trim($reply);
    }

    private function systemPrompt(string $audience): string
    {
        if ($audience === 'staff') {
            return
                "You are Ka-Agapay RHU Staff Assistant for RHU Malasiqui, Pangasinan. " .
                "You help RHU staff/admins use the admin dashboard modules: Dashboard, Queue, Appointments, Consultations, Telemedicine, Prescriptions, Inventory, Analytics, Heatmap, CMS Announcements/Events, SMS, Reports, Users, and Settings. " .
                "Give practical step-by-step tutorial guidance. " .
                "Use short, clear instructions. " .
                "You may explain workflows, where to click, what data to check, and what errors mean. " .
                "Do not invent database records. " .
                "Do not expose API keys, passwords, or secrets. " .
                "For clinical questions, do not diagnose; advise staff to follow RHU protocol and escalate to a licensed clinician. " .
                "Reply in the same language the user uses when possible.";
        }

        return
            "You are Ka-Agapay Mobile Health Assistant for residents of Malasiqui, Pangasinan. " .
            "You help residents use the mobile app: booking appointments, checking records, viewing events, uploading ID verification, using telemedicine, and understanding RHU services. " .
            "Give simple and warm health guidance. " .
            "Never diagnose diseases. " .
            "For emergency symptoms such as chest pain, severe bleeding, difficulty breathing, stroke signs, seizures, or loss of consciousness, tell the user to go to the nearest ER or call emergency help immediately. " .
            "Keep replies short, safe, and easy to understand. " .
            "Reply in the same language the user uses when possible.";
    }

    private function buildUserPrompt(string $message, string $audience, array $context): string
    {
        $contextText = '';

        if (!empty($context)) {
            $safeContext = collect($context)
                ->only([
                    'current_page',
                    'module',
                    'role',
                    'barangay',
                    'language',
                    'app_section',
                ])
                ->filter(fn ($value) => is_scalar($value) && $value !== '')
                ->map(fn ($value, $key) => "{$key}: {$value}")
                ->implode("\n");

            if ($safeContext !== '') {
                $contextText = "\n\nContext:\n{$safeContext}";
            }
        }

        $audienceText = $audience === 'staff'
            ? 'Audience: RHU staff/admin user.'
            : 'Audience: mobile resident user.';

        return "{$audienceText}{$contextText}\n\nUser message:\n{$message}";
    }

    private function ruleBasedResponse(string $message, string $audience): ?string
    {
        $lower = mb_strtolower($message);

        $emergencyKeywords = [
            'chest pain',
            'sakit dibdib',
            'hirap huminga',
            'hindi makahinga',
            'stroke',
            'seizure',
            'nawalan ng malay',
            'unconscious',
            'severe bleeding',
            'malakas na dugo',
            'dumudugo nang malakas',
        ];

        foreach ($emergencyKeywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return
                    "⚠️ Posibleng emergency ito. Pumunta agad sa pinakamalapit na ospital o ER, " .
                    "o humingi agad ng tulong sa RHU/ambulance. Huwag maghintay.";
            }
        }

        if ($audience === 'staff') {
            if ($this->containsAny($lower, ['tutorial', 'guide', 'how to use', 'paano gamitin', 'turo'])) {
                return
                    "Narito ang mabilis na guide:\n\n" .
                    "1. Dashboard — tingnan ang daily summary at alerts.\n" .
                    "2. Queue — tawagin, i-serve, o tapusin ang queue tickets.\n" .
                    "3. Appointments — approve, reschedule, cancel, or start consultation.\n" .
                    "4. Consultations — record diagnosis, notes, prescriptions, and follow-up.\n" .
                    "5. CMS — gumawa ng announcements/events para makita sa mobile app.\n" .
                    "6. SMS — piliin ang target demographics at mag-send ng campaign.\n\n" .
                    "Sabihin mo kung anong module ang gusto mong ituro ko step-by-step.";
            }

            if ($this->containsAny($lower, ['queue', 'pila'])) {
                return
                    "Para sa Queue module:\n\n" .
                    "1. Buksan ang Queue page.\n" .
                    "2. Piliin ang station/counter.\n" .
                    "3. I-click ang Call Next para tawagin ang susunod.\n" .
                    "4. I-click ang Serving kapag nasa counter na ang pasyente.\n" .
                    "5. I-click ang Done kapag tapos na.\n\n" .
                    "Kung priority patient, tingnan ang priority flags gaya ng senior, pregnant, PWD, emergency, o BHW-assisted.";
            }

            if ($this->containsAny($lower, ['appointment', 'appointments', 'booking'])) {
                return
                    "Para sa Appointments module:\n\n" .
                    "1. Buksan ang Appointments page.\n" .
                    "2. Tingnan ang pending requests.\n" .
                    "3. I-review ang appointment type: online, onsite, or consultation.\n" .
                    "4. Approve, reschedule, cancel, or start consultation.\n" .
                    "5. Kapag kailangan ng clinical record, buksan ang Consultation details.";
            }

            if ($this->containsAny($lower, ['announcement', 'event', 'cms', 'post'])) {
                return
                    "Para mag-post ng announcement/event:\n\n" .
                    "1. Buksan ang Announcements o Events page.\n" .
                    "2. I-click ang New Announcement o New Event.\n" .
                    "3. Ilagay ang title, content, category, date/location kung event.\n" .
                    "4. Upload at crop banner image kung meron.\n" .
                    "5. Piliin ang Published kung gusto mong makita agad sa mobile app.\n" .
                    "6. I-click Save/Create.";
            }

            if ($this->containsAny($lower, ['sms', 'semaphore', 'text blast'])) {
                return
                    "Para sa SMS module:\n\n" .
                    "1. Buksan ang SMS page.\n" .
                    "2. Piliin ang target demographics: barangay, age group, sex, program, or status.\n" .
                    "3. Isulat ang maikling message.\n" .
                    "4. I-preview muna ang recipients.\n" .
                    "5. I-send kapag tama na.\n\n" .
                    "Siguraduhin na may Semaphore credits at valid API key sa backend .env.";
            }
        }

        if ($audience === 'resident') {
            if ($this->containsAny($lower, ['book', 'appointment', 'schedule', 'konsultasyon'])) {
                return
                    "Pwede kang mag-book ng appointment sa app. Pumunta sa Appointments, piliin ang Create Appointment, " .
                    "ilagay ang concern, petsa, at preferred RHU service, pagkatapos i-submit.";
            }

            if ($this->containsAny($lower, ['record', 'records', 'rekord', 'history'])) {
                return
                    "Para makita ang records mo, pumunta sa Records o Consultations section ng app. " .
                    "Makikita doon ang previous consultations, diagnosis notes kung available, at prescriptions.";
            }

            if ($this->containsAny($lower, ['id', 'verify', 'verification', 'ocr', 'upload'])) {
                return
                    "Para sa ID verification, pumunta sa Profile, piliin ang ID Verification, " .
                    "upload ng malinaw na ID photo, at hintayin ang result. Iwasan ang blur, glare, at putol na image.";
            }
        }

        return null;
    }

    private function fallbackResponse(string $message, string $audience): string
    {
        if ($audience === 'staff') {
            return
                "Nandito ako para tumulong sa RHU admin system. Pwede kitang gabayan sa Queue, Appointments, " .
                "Consultations, Telemedicine, Prescriptions, Inventory, Analytics, CMS posts, SMS, Users, at Settings. " .
                "Sabihin mo lang kung anong module ang gusto mong gawin.";
        }

        return
            "Nandito ako para tumulong sa Ka-Agapay app. Pwede kitang gabayan sa appointment booking, " .
            "events, records, telemedicine, ID verification, at RHU services. Kung emergency ang nararamdaman, pumunta agad sa ER o humingi ng tulong.";
    }

    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }
}