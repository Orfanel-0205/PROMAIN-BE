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
                    'temperature' => 0.35,
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
            $serviceCatalog = implode('; ', [
                'Konsulta/Maternal & Newborn Care (Prenatal, Post-natal, Labor & Delivery, Newborn Care, Consultation/Outpatient)',
                'Family Planning', 'Child Care', 'Immunization',
                'Nutrition (Micronutrient Supplementation, Growth Monitoring, Nutrition Counseling)',
                'Adolescent Services', 'Dental', 'TB-DOTS',
                'Morbid Clinics (Clinic-Based/Outreach Consultation)',
                'Minor Surgery', 'Referral',
                'Ancillary (Laboratory, Chest X-ray, ECG, Pharmacy, Ambulance)',
                'Administrative (Medical Certificates, Sanitary Permits)',
                'Environmental Health & Sanitation', 'HIV/AIDS & STI Counseling',
                'Leprosy Control', 'Healthy Lifestyle & NCD Prevention',
                'Dengue Control', 'Rabies Control',
                'Infectious/Communicable Disease Control', 'Mental Health',
            ]);

            return
                "You are Ka-Agapay RHU Staff Assistant for the Rural Health Units of Malasiqui, Pangasinan. " .
                "You support authorized RHU staff/admin users in the admin dashboard. " .
                "MANNER: speak the way a courteous Philippine government health office speaks — professional, warm, and respectful " .
                "(gumamit ng 'po'/'opo' kapag Tagalog o Taglish ang user), concise, and never condescending. Mirror the user's language (English, Tagalog, or Taglish). " .
                "Use professional, step-by-step guidance aligned with Ka-Agapay workflows: queue management, appointments, consultations, telemedicine, e-prescriptions, inventory, reports, CMS events/announcements, SMS, analytics, and user verification. " .
                "IMPORTANT STYLE RULE: refer to clickable navigation items as buttons, not modules. Say 'click the Events button', 'click the Queue button'. " .
                "CONTENT DRAFTING — one of your most useful jobs: when staff ask for help creating an event, program, or announcement " .
                "(e.g. 'help me create an event for Sex Education'), do NOT just tell them where to click. Produce a ready-to-copy draft they can paste into the Event form: " .
                "1) Title (short, plain-language); 2) Category; 3) Description (simple words residents understand — what it is, who may join, what to bring, no graphic detail); " .
                "4) Suggested Target Audience groups (choose from: Infants, Children, Adolescents/Youth, Adults, Senior Citizens, Pregnant Women, Lactating Mothers, PWDs, Solo Parents, Indigent Families, 4Ps Beneficiaries, Farmers/Fisherfolk, Barangay Health Workers, Others); " .
                "5) Suggested RHU Service classification, chosen ONLY from this official catalog: {$serviceCatalog}; " .
                "6) SMS Summary of AT MOST 160 characters (this exact text is TEXTED to residents when the post is published, and a reminder is auto-texted 3 days before the event — keep it complete and self-contained); " .
                "then 7) the click-path: click the Events button → Create Event → fill the sections → Create & Publish. " .
                "For sensitive health topics (sex education, HIV, family planning, mental health), keep drafts factual, non-graphic, stigma-free, and aligned with DOH health-promotion tone. " .
                "OPERATIONAL MATH: you SHOULD do basic arithmetic for everyday RHU tasks when the user gives you the numbers — " .
                "e.g. stock remaining after dispensing (40 − 15 = 25 units left), days until an expiry date the user states, " .
                "totals, differences, averages, and simple percentages. Show the computation in one short line so staff can double-check it. " .
                "Use ONLY numbers the user provides in the conversation. You have NO access to live system data: " .
                "never invent or guess patient records, live stock counts, queue numbers, inventory quantities, or delivery statuses. " .
                "If staff ask for a live figure you cannot see, say so and point them to the right button (e.g. 'click the Inventory button for the current stock count'), " .
                "then offer to compute with the number once they read it back to you. " .
                "Do not compute medication DOSES or clinical dosages — that is clinical work for a licensed clinician. " .
                "Do not expose API keys, passwords, or secrets. " .
                "For clinical questions, do not diagnose; instruct staff to follow RHU protocol and escalate to a licensed clinician.";
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
                    'current_button',
                    'role',
                    'barangay',
                    'language',
                    'app_section',
                    'source',
                ])
                ->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
                ->map(fn ($value, $key) => "{$key}: {$value}")
                ->implode("\n");

            if ($safeContext !== '') {
                $contextText = "\n\nContext:\n{$safeContext}";
            }
        }

        $audienceText = $audience === 'staff'
            ? 'Audience: RHU staff/admin user. Use button names, not module labels.'
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
            // Panelist follow-up round: computational questions must reach
            // Gemini, not a canned navigation walkthrough. Without this, a
            // question like "if I dispense 15 from a stock of 40, how many
            // remain?" matched the 'stock' rule below and got a click-here
            // answer. Numbers + calculation intent → skip the rules.
            if (
                preg_match('/\d/', $lower) === 1 &&
                $this->containsAny($lower, [
                    'how many', 'how much', 'remain', 'left', 'compute',
                    'calculate', 'kalkula', 'ilan', 'magkano', 'matitira',
                    'natira', 'days until', 'ilang araw', 'expire', 'expiry',
                    'average', 'percent', '%', 'total', 'sum', 'difference',
                    'minus', 'plus', 'times', 'divide', '+', '-', '*', '/',
                ])
            ) {
                return null;
            }

            // SPECIFIC task requests must also reach Gemini. "Help me create
            // an event for Sex Education" used to hit the generic 'event'
            // keyword rule and got a click-here walkthrough instead of an
            // actual draft. Longer messages with drafting/assistance intent
            // skip the canned rules; short generic questions ("how to post an
            // event?") keep their fast canned answers.
            $wordCount = count(preg_split('/\s+/', trim($lower)) ?: []);

            if (
                $wordCount >= 5 &&
                $this->containsAny($lower, [
                    'help me', 'assist me', 'create a', 'create an', 'make a',
                    'make an', 'draft', 'write', 'suggest', 'recommend',
                    'gumawa', 'gawan', 'isulat', 'buuin', 'tulungan',
                    'magmungkahi', ' for ', ' para sa ', ' tungkol sa ',
                    ' about ',
                ])
            ) {
                return null;
            }

            if ($this->containsAny($lower, ['report', 'reports', 'ulat', 'pinamigay', 'dispensed', 'export', 'csv'])) {
                return
                    "Para gumawa ng report sa mga pinamigay na gamot:\n\n" .
                    "1. I-click ang **Reports** button sa sidebar.\n" .
                    "2. Piliin ang report type para sa medicine dispensing, prescriptions, o inventory usage.\n" .
                    "3. I-set ang date range, RHU, barangay, medicine name, o patient/program filter kung available.\n" .
                    "4. I-click ang Preview para i-check kung tama ang records.\n" .
                    "5. I-click ang Export CSV/PDF o Print para sa RHU documentation.\n\n" .
                    "Kung stock count ang kailangan mong tingnan, i-click ang **Inventory** button. Kung actual reseta naman, i-click ang **Prescriptions** button.";
            }

            if ($this->containsAny($lower, ['tutorial', 'guide', 'how to use', 'paano gamitin', 'turo'])) {
                return
                    "Narito ang mabilis na guide sa admin dashboard:\n\n" .
                    "1. I-click ang **Dashboard** button para makita ang daily summary at alerts.\n" .
                    "2. I-click ang **Queue** button para tumawag, mag-serve, at magtapos ng tickets.\n" .
                    "3. I-click ang **Appointments** button para mag-approve, mag-reschedule, o magsimula ng consultation.\n" .
                    "4. I-click ang **Consultations** button para mag-record ng diagnosis, notes, prescriptions, at follow-up.\n" .
                    "5. I-click ang **CMS** button para gumawa ng announcements/events para sa mobile app.\n" .
                    "6. I-click ang **SMS** button para pumili ng target demographics at mag-send ng campaign.\n" .
                    "7. I-click ang **Reports** button para gumawa ng printable/exportable RHU reports.\n\n" .
                    "Sabihin mo kung aling button ang gusto mong i-walkthrough step-by-step.";
            }

            if ($this->containsAny($lower, ['queue', 'pila'])) {
                return
                    "Para sa pila workflow:\n\n" .
                    "1. I-click ang **Queue** button.\n" .
                    "2. Piliin ang station/counter.\n" .
                    "3. I-click ang **Call Next** para tawagin ang susunod.\n" .
                    "4. I-click ang **Serving** kapag nasa counter na ang pasyente.\n" .
                    "5. I-click ang **Done** kapag tapos na.\n\n" .
                    "Reviewhin ang priority flags tulad ng senior, pregnant, PWD, emergency, pediatric, o BHW-assisted bago magdesisyon.";
            }

            if ($this->containsAny($lower, ['appointment', 'appointments', 'booking'])) {
                return
                    "Para mag-manage ng appointments:\n\n" .
                    "1. I-click ang **Appointments** button.\n" .
                    "2. Buksan ang pending requests.\n" .
                    "3. I-review ang appointment type: online, onsite, o consultation.\n" .
                    "4. Piliin ang approve, reschedule, cancel, o start consultation.\n" .
                    "5. Kapag kailangan ng clinical record, buksan ang related consultation details.";
            }

            if ($this->containsAny($lower, ['announcement', 'event', 'cms', 'post', 'program'])) {
                return
                    "Para mag-post ng announcement o event:\n\n" .
                    "1. I-click ang **CMS** button o **Events** button, depende sa screen ninyo.\n" .
                    "2. I-click ang **New Announcement** o **New Event**.\n" .
                    "3. Ilagay ang title, content, category, date, at location kung event.\n" .
                    "4. Upload at i-crop ang banner image kung meron.\n" .
                    "5. Piliin ang **Published** kung gusto mong makita agad sa mobile app.\n" .
                    "6. I-click ang **Save** o **Create**.";
            }

            if ($this->containsAny($lower, ['sms', 'semaphore', 'text blast', 'notification'])) {
                return
                    "Para mag-send ng SMS campaign:\n\n" .
                    "1. I-click ang **SMS** button.\n" .
                    "2. Piliin ang target demographics: barangay, age group, sex, program, o account status.\n" .
                    "3. Gumamit ng maikling message na walang sensitibong medical details.\n" .
                    "4. I-click ang **Preview Recipients** at i-check ang count.\n" .
                    "5. I-click ang **Send** kapag tama na.\n\n" .
                    "Siguraduhin na may valid Semaphore API key at credits sa backend bago mag-send.";
            }

            if ($this->containsAny($lower, ['user', 'approve', 'verify', 'account', 'ocr'])) {
                return
                    "Para mag-approve o mag-check ng users:\n\n" .
                    "1. I-click ang **Users** button.\n" .
                    "2. Piliin ang pending, active, o rejected accounts.\n" .
                    "3. I-review ang profile details, ID upload, at OCR/verification result kung meron.\n" .
                    "4. I-click ang **Approve**, **Reject**, o request correction ayon sa RHU validation rules.\n" .
                    "5. Iwasang mag-approve kung kulang o hindi tugma ang identity details.";
            }

            if ($this->containsAny($lower, ['inventory', 'stock', 'medicine', 'gamot', 'vaccine'])) {
                return
                    "Para sa gamot o vaccine stock:\n\n" .
                    "1. I-click ang **Inventory** button.\n" .
                    "2. Hanapin ang medicine/vaccine item.\n" .
                    "3. I-check ang current stock, low-stock alert, expiry date, at transaction history.\n" .
                    "4. Gamitin ang stock-in, stock-out, o adjust only with proper RHU documentation.\n" .
                    "5. Para sa printable summary, i-click ang **Reports** button.";
            }

            if ($this->containsAny($lower, ['analytics', 'dashboard', 'heatmap', 'trend'])) {
                return
                    "Para sa analytics:\n\n" .
                    "1. I-click ang **Dashboard** button para sa daily operational summary.\n" .
                    "2. I-click ang **Analytics** button para sa trends, totals, at service performance.\n" .
                    "3. I-click ang **Heatmap** button para makita ang barangay distribution at high-risk patterns.\n" .
                    "4. Gamitin ang filters bago gumawa ng decisions o reports.";
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
                "Nandito ako para tumulong sa RHU admin dashboard. Pwede kitang gabayan sa **Dashboard**, **Queue**, **Appointments**, " .
                "**Consultations**, **Telemedicine**, **Prescriptions**, **Inventory**, **Analytics**, **Reports**, **CMS**, **SMS**, **Users**, at **Settings** buttons. " .
                "Sabihin mo ang task, halimbawa: “gumawa ng medicine report”, “send SMS”, o “approve user”.";
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
