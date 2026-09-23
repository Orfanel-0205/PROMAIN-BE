<?php
// app/Services/Ai/GeminiService.php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    /**
     * Token budgets. NOTE: on gemini-2.5-* the model's internal thinking tokens
     * are charged against maxOutputTokens, so the visible answer only ever gets
     * (maxOutputTokens - thinkingBudget). These values keep a guaranteed floor
     * of visible tokens instead of letting thinking starve the reply.
     */
    /** Assistant personas. Operations = day-to-day help; Tutorial = onboarding. */
    public const MODE_OPERATIONS = 'operations';
    public const MODE_TUTORIAL = 'tutorial';

    private const THINKING_BUDGET = 512;
    private const DEFAULT_OUTPUT_TOKENS = 1600;
    private const LONG_FORM_OUTPUT_TOKENS = 3200;
    private const RETRY_OUTPUT_TOKENS = 4000;

    private string $apiKey;
    private string $model = 'gemini-2.5-flash';
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct(
        private readonly AssistantTools $tools = new AssistantTools(),
        private readonly AnswerBook $answers = new AnswerBook(),
    ) {
        $this->apiKey = (string) (
            config('services.google.gemini_api_key')
            ?: env('GEMINI_API_KEY', '')
        );
    }

    /**
     * Shared chatbot for mobile residents and RHU admin/staff.
     */
    /**
     * @param ?User $actor The signed-in staff member. Given one, the assistant
     *                     may look up counts in this RHU's own records
     *                     (AssistantTools) instead of guessing.
     */
    /**
     * @param array<int, array{mime:string, data:string}> $attachments
     *        A photo or a voice note the person sent with the message,
     *        base64-encoded. Passed straight to the model and never
     *        written to disk -- see ChatController::attachmentPart().
     */
    public function chat(
        string $message,
        array $history = [],
        string $audience = 'resident',
        array $context = [],
        ?User $actor = null,
        array $attachments = []
    ): string {
        $message = trim($message);

        if ($message === '' && $attachments === []) {
            return $this->fallbackResponse($message, $audience);
        }

        $mode = $this->resolveMode($context, $audience);

        /*
         * A photo or a voice note always reaches the model.
         *
         * The keyword rules can only read text. Someone who photographs a
         * rash and types nothing, or records "bakit masakit ang ulo ko"
         * and types nothing, has a message the rules cannot see -- and
         * would get a canned navigation answer to a question they never
         * asked. The emergency guard below still runs on whatever text
         * there is.
         */
        $ruleBased = ($attachments !== [] || $this->prefersModelReply($context))
            ? $this->emergencyResponse($message)
            : $this->ruleBasedResponse($message, $audience, $mode);

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
            return $this->callGeminiApi($message, $history, $audience, $context, $mode, $actor, $attachments);
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
        array $context,
        string $mode = self::MODE_OPERATIONS,
        ?User $actor = null,
        array $attachments = []
    ): string {
        $history = array_slice($history, -8);
        // Onboarding walkthroughs are inherently multi-step, so they always get
        // the long budget.
        $longForm = $mode === self::MODE_TUTORIAL
            || $this->needsLongForm($message, $audience);

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

        $parts = [
            [
                'text' => $this->buildUserPrompt($message, $audience, $context, $attachments),
            ],
        ];

        // The photo or voice note goes in the same turn as the text, so
        // the model reads "is this infected?" and the picture together.
        foreach ($attachments as $attachment) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $attachment['mime'],
                    'data' => $attachment['data'],
                ],
            ];
        }

        $contents[] = [
            'role' => 'user',
            'parts' => $parts,
        ];

        $url = "{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}";

        // Staff questions can be answered from Ka-Agapay's own data rather than
        // guessed at: counts, stock levels, follow-ups. Read-only, aggregate
        // only, and scoped to the staff member's RHU — see AssistantTools.
        $tools = $audience === 'staff' && $actor !== null
            ? [['functionDeclarations' => $this->tools->declarations()]]
            : [];

        // gemini-2.5-flash is a THINKING model: its internal "thoughts" tokens are
        // billed against maxOutputTokens. The old flat cap of 700 left almost
        // nothing for the visible answer once the staff system prompt grew - a
        // full CMS draft request measured finishReason=MAX_TOKENS with 669
        // thinking tokens and only 26 visible ones, which is exactly the
        // "starts the draft then stops" bug. So: bound the thinking budget and
        // give the visible answer real headroom, then retry once (thinking off)
        // if the model still runs out of room.
        $attempts = $longForm
            ? [[self::LONG_FORM_OUTPUT_TOKENS, self::THINKING_BUDGET], [self::RETRY_OUTPUT_TOKENS, 0]]
            : [[self::DEFAULT_OUTPUT_TOKENS, self::THINKING_BUDGET], [self::LONG_FORM_OUTPUT_TOKENS, 0]];

        $lastReply = null;

        foreach ($attempts as [$maxOutputTokens, $thinkingBudget]) {
            $response = Http::timeout($longForm ? 45 : 25)
                ->retry(2, 700)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($url, array_filter([
                    'tools' => $tools,
                    'contents' => $contents,
                    'systemInstruction' => [
                        'parts' => [
                            [
                                'text' => $this->systemPrompt($audience, $mode),
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.35,
                        'maxOutputTokens' => $maxOutputTokens,
                        'thinkingConfig' => [
                            'thinkingBudget' => $thinkingBudget,
                        ],
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
                ]));

            if ($response->status() === 429) {
                Cache::put('gemini_cooldown', true, now()->addMinutes(2));
                return $lastReply ?? $this->fallbackResponse($message, $audience);
            }

            if (!$response->successful()) {
                Log::warning('[GeminiService] Gemini request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 1000),
                ]);

                return $lastReply ?? $this->fallbackResponse($message, $audience);
            }

            $payload = $response->json();
            $candidate = $payload['candidates'][0] ?? [];
            $finishReason = (string) ($candidate['finishReason'] ?? '');

            // The model asked for a lookup before answering. Run it, hand the
            // result back, and let it write the reply around real numbers.
            $call = $this->functionCallIn($candidate);

            if ($call !== null && $actor !== null) {
                $answer = $this->answerWithToolResult($call, $actor, $contents, $candidate, $url, $audience, $mode, $maxOutputTokens);

                if ($answer !== null) {
                    return $answer;
                }
            }

            $reply = $this->textIn($candidate);

            if ($reply !== '') {
                $lastReply = $reply;
            }

            // Complete answer - done.
            if ($reply !== '' && $finishReason !== 'MAX_TOKENS') {
                return $reply;
            }

            Log::warning('[GeminiService] Reply truncated or empty, retrying with more room', [
                'finish_reason' => $finishReason ?: 'none',
                'max_output_tokens' => $maxOutputTokens,
                'thinking_budget' => $thinkingBudget,
                'thoughts_tokens' => $payload['usageMetadata']['thoughtsTokenCount'] ?? null,
                'visible_tokens' => $payload['usageMetadata']['candidatesTokenCount'] ?? null,
                'long_form' => $longForm,
            ]);
        }

        return $lastReply ?? $this->fallbackResponse($message, $audience);
    }

    /**
     * Same answer as chat(), delivered a piece at a time.
     *
     * Staff wait less: the first words appear in about a second instead of the
     * whole paragraph arriving after four. $onChunk receives each new piece as
     * it comes; the complete text is returned for storing.
     *
     * Deliberately without tools. A mid-stream function call would mean
     * stopping, querying, and starting a second stream, and the questions that
     * need a lookup ("how many are waiting?") are short enough that the
     * ordinary path already answers them quickly.
     */
    public function streamChat(
        string $message,
        array $history,
        string $audience,
        array $context,
        callable $onChunk
    ): string {
        $message = trim($message);

        if ($message === '' || $this->apiKey === '') {
            return $this->fallbackResponse($message, $audience);
        }

        $mode = $this->resolveMode($context, $audience);
        $ruleBased = $this->prefersModelReply($context)
            ? null
            : $this->ruleBasedResponse($message, $audience, $mode);

        if ($ruleBased !== null) {
            $onChunk($ruleBased);

            return $ruleBased;
        }

        $contents = [];

        foreach (array_slice($history, -8) as $item) {
            $text = trim((string) ($item['content'] ?? ''));

            if ($text !== '') {
                $contents[] = [
                    'role' => ($item['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $text]],
                ];
            }
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $this->buildUserPrompt($message, $audience, $context)]],
        ];

        $url = "{$this->baseUrl}/{$this->model}:streamGenerateContent?alt=sse&key={$this->apiKey}";
        $full = '';

        try {
            $response = Http::timeout(45)
                ->withOptions(['stream' => true])
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, [
                    'contents' => $contents,
                    'systemInstruction' => [
                        'parts' => [['text' => $this->systemPrompt($audience, $mode)]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.35,
                        'maxOutputTokens' => self::LONG_FORM_OUTPUT_TOKENS,
                        'thinkingConfig' => ['thinkingBudget' => 0],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('[GeminiService] Stream request failed', ['status' => $response->status()]);

                return $this->fallbackResponse($message, $audience);
            }

            $body = $response->toPsrResponse()->getBody();
            $buffer = '';

            while (!$body->eof()) {
                $buffer .= $body->read(1024);

                // Server-sent events arrive as "data: {...}" lines, and a chunk
                // can split mid-line, so only whole lines are parsed.
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $newline));
                    $buffer = substr($buffer, $newline + 1);

                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $json = json_decode(trim(substr($line, 5)), true);

                    if (!is_array($json)) {
                        continue;
                    }

                    $piece = $this->textIn($json['candidates'][0] ?? []);

                    if ($piece !== '') {
                        $full .= $piece;
                        $onChunk($piece);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[GeminiService] Stream failed', ['error' => $e->getMessage()]);

            return $full !== '' ? $full : $this->fallbackResponse($message, $audience);
        }

        return $full !== '' ? $full : $this->fallbackResponse($message, $audience);
    }

    /**
     * The lookup the model asked for, if it asked for one.
     *
     * @return array{name: string, args: array<string, mixed>}|null
     */
    private function functionCallIn(array $candidate): ?array
    {
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            $name = $part['functionCall']['name'] ?? null;

            if (is_string($name) && $this->tools->has($name)) {
                return [
                    'name' => $name,
                    'args' => (array) ($part['functionCall']['args'] ?? []),
                ];
            }
        }

        return null;
    }

    private function textIn(array $candidate): string
    {
        $text = '';

        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        return trim($text);
    }

    /**
     * Run the lookup and ask the model again with the answer in hand.
     *
     * One round only: enough for "how many are waiting?", and it bounds both
     * the wait and the number of queries a single question can trigger.
     */
    private function answerWithToolResult(
        array $call,
        User $actor,
        array $contents,
        array $candidate,
        string $url,
        string $audience,
        string $mode,
        int $maxOutputTokens
    ): ?string {
        try {
            $result = $this->tools->run($call['name'], $call['args'], $actor);
        } catch (\Throwable $e) {
            Log::warning('[GeminiService] Assistant tool failed', [
                'tool' => $call['name'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $contents[] = $candidate['content'];
        $contents[] = [
            'role' => 'user',
            'parts' => [[
                'functionResponse' => [
                    'name' => $call['name'],
                    'response' => ['result' => $result],
                ],
            ]],
        ];

        try {
            $response = Http::timeout(25)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, [
                    'contents' => $contents,
                    'systemInstruction' => [
                        'parts' => [[
                            'text' => $this->systemPrompt($audience, $mode)
                                . ' The figures you were just given are live from this RHU\'s own records. '
                                . 'State them plainly with the time they were read, and never invent a number that was not returned.',
                        ]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => $maxOutputTokens,
                        'thinkingConfig' => ['thinkingBudget' => 0],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('[GeminiService] Follow-up call failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $reply = $this->textIn($response->json()['candidates'][0] ?? []);

        return $reply !== '' ? $reply : null;
    }

    /**
     * Requests that legitimately need a long, complete answer: CMS content
     * drafts (which must cover every field of the Event/Announcement form) and
     * troubleshooting walkthroughs. Everything else keeps a tighter budget so
     * simple navigation questions stay short.
     */
    private function needsLongForm(string $message, string $audience): bool
    {
        if ($audience !== 'staff') {
            return false;
        }

        $lower = mb_strtolower($message);

        return $this->containsAny($lower, [
            // drafting intent
            'draft', 'create a', 'create an', 'make a', 'make an', 'write',
            'compose', 'help me create', 'help me make', 'suggest', 'recommend',
            'gumawa', 'gawan', 'isulat', 'buuin', 'tulungan', 'magmungkahi',
            // content nouns
            'event', 'announcement', 'program', 'campaign', 'advisory',
            // troubleshooting intent
            'why is', 'why isn', 'why did', 'why does', 'not showing',
            'not appearing', 'hindi lumalabas', 'hindi makita', 'bakit',
            'failed', 'error', 'troubleshoot', 'fix', 'ayusin', 'problema',
            // walkthroughs
            'step by step', 'walkthrough', 'tutorial', 'paano',
        ]);
    }

    private function systemPrompt(string $audience, string $mode = self::MODE_OPERATIONS): string
    {
        if ($audience === 'staff' && $mode === self::MODE_TUTORIAL) {
            return $this->tutorialSystemPrompt();
        }

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
                "ACCURACY, above everything: describe only buttons, screens and fields that exist in Ka-Agapay. Never invent a search bar, filter, export or report. " .
                "If you are not certain a feature exists, say so plainly and name the closest screen that does, rather than guessing a sequence of steps. " .
                "LENGTH: answer in at most four sentences or four numbered steps. Lead with the action, not with background. These are working staff mid-shift, not students. " .
                "NEVER pad an answer with generic computer advice ('use the search bar, usually found at the top'); if you do not know where something is on that screen, say which screen it is on and stop. " .
                "CONTENT DRAFTING \u2014 one of your most useful jobs: when staff ask for help creating an event, program, or announcement " .
                "(e.g. \'help me create an event about feeding program\'), do NOT just tell them where to click, and do NOT stop after the first two or three fields. " .
                "Produce a COMPLETE, ready-to-copy draft that fills EVERY field of the actual Ka-Agapay CMS form, in this exact order, each on its own labelled line. " .
                "You must output all of the following fields every single time, even when the staff message is short \u2014 never truncate the draft and never end after Title/Category: " .
                "1) Post Type (choose exactly one: Event, Program, or Announcement); " .
                "2) Category (short free-text label, e.g. Immunization, Nutrition, Medical Mission, Dental); " .
                "3) Title (required, at least 5 characters, short and plain-language); " .
                "4) Description (required, at least 10 characters \u2014 simple words residents understand: what it is, who may join, what to bring, no graphic detail); " .
                "5) Start Date & Time (required for Event/Program; propose a concrete realistic future date and time, e.g. \'2026-09-15 08:00 AM\', and remind staff to confirm it \u2014 a past schedule cannot be published); " .
                "6) End Date & Time (optional; if given it must NOT be earlier than the start); " .
                "7) Location / Venue (required for Event/Program \u2014 suggest a realistic Malasiqui RHU or barangay venue, e.g. \'RHU 1 Covered Court, Poblacion, Malasiqui\'); " .
                "8) Target Audience (choose the fitting groups ONLY from: Infants (0-11 months), Children, Adolescents / Youth, Adults, Senior Citizens, Pregnant Women, Lactating Mothers, PWDs (Persons with Disabilities), Solo Parents, Indigent Families, 4Ps Beneficiaries, Farmers / Fisherfolk, Barangay Health Workers, Others); " .
                "9) Barangay Target (either \'All barangays\' or a specific list of Malasiqui barangays); " .
                "10) Maximum Slots (a whole number of at least 1, or \'No limit\' when the activity is open to everyone); " .
                "11) RHU Service Offered, chosen ONLY from this official catalog: {$serviceCatalog}; " .
                "12) Priority (Normal, High, or Urgent \u2014 Urgent is only for time-critical advisories); " .
                "13) Visibility (Public = all residents of both RHUs, RHU 1 = RHU 1 residents only, RHU 2 = RHU 2 residents only); " .
                "14) Tags (2 to 5 short keywords, comma-separated); " .
                "15) SMS Summary of AT MOST 160 characters (this exact text is TEXTED to residents when the post is published, and a reminder is auto-texted 3 days before the event \u2014 keep it complete and self-contained; state the character count in parentheses after it); " .
                "then a short \'Banner image\' note reminding staff to upload and crop a banner themselves (you cannot generate images), " .
                "then say whether to Save as Draft or Create & Publish, " .
                "and finally the click-path: click the Events button \u2192 Create Event \u2192 fill the sections with the draft above \u2192 Create & Publish. " .
                "This full-draft rule applies no matter which button/screen the staff member is currently on \u2014 whether they are on the Dashboard, the Queue, or already inside the Events form, always return the complete draft. " .
                "For sensitive health topics (sex education, HIV, family planning, mental health), keep drafts factual, non-graphic, stigma-free, and aligned with DOH health-promotion tone. " .
                "TROUBLESHOOTING \u2014 when staff ask why something is not working (\'why isn\'t this appointment showing in queue\', " .
                "\'why did this registration fail\', \'bakit hindi lumalabas\'), do NOT give generic advice and do NOT invent a cause. " .
                "You cannot see the actual record, so instead give a SHORT ranked checklist of the causes that really occur in this system, " .
                "most likely first, each with the exact check or fix. Say plainly that you cannot see the record and that they should confirm each item on screen. " .
                "These are the real, system-verified causes \u2014 use them, not guesses: " .
                "(A) APPOINTMENT NOT IN THE QUEUE: it is still Pending \u2014 it must be approved/scheduled before Add to Queue works; " .
                "it is Cancelled, Rejected, or Completed \u2014 closed appointments cannot be queued; " .
                "it is an ONLINE/telemedicine appointment \u2014 those never get an onsite queue ticket, open the Telemedicine button instead; " .
                "it belongs to a different RHU \u2014 staff may only manage their assigned RHU; " .
                "it is scheduled for another date \u2014 the active onsite queue board shows TODAY only; " .
                "it was already added \u2014 check the other status views before re-adding; " .
                "or the wrong service desk/counter is selected \u2014 each desk shows only its own service. " .
                "(B) REGISTRATION / ID VERIFICATION FAILED: the ID photo is blurred, glared, or cropped so the name could not be read; " .
                "the typed first and last name are not both visible on the ID (middle names and suffixes are never required); " .
                "the PhilHealth ID name does not match the profile name; " .
                "or the account is simply still Pending and waiting for staff approval under the Users button. " .
                "(C) STAFF REGISTRATION LINK NOT WORKING: staff registration is invitation-only via a signed one-time link \u2014 " .
                "the link has EXPIRED, was REVOKED, or was ALREADY USED (each link works exactly once). The fix is always a new invite from a Super Admin. " .
                "(D) EVENT/ANNOUNCEMENT WILL NOT PUBLISH: the schedule is in the past (a past event cannot be published); " .
                "the title is under 5 characters or the description under 10; a date and location are missing (both required for Event/Program, not Announcement); " .
                "the end date is earlier than the start; or the SMS summary is over 160 characters. " .
                "(E) RESIDENTS CANNOT SEE A PUBLISHED POST: visibility is set to RHU 1 or RHU 2 instead of Public, " .
                "the Barangay Target excludes them, or the post is still saved as a Draft. " .
                "(F) RESIDENT CANNOT JOIN AN EVENT: the event has no available slots left. " .
                "(G) CANNOT MESSAGE A CO-WORKER IN TEAM CHAT: that staff member is in a different RHU (only Super Admin/MHO span both). " .
                "(H) CANNOT CREATE AN ACCOUNT: the email or mobile number is already registered \u2014 including on a previously deleted account, " .
                "because deleted records are archived, never hard-deleted; or the mobile number is not in 09XXXXXXXXX format. " .
                "(I) SMS LOGS LOOK EMPTY: the SMS log view defaults to TODAY \u2014 widen the date range to see older sends. " .
                "(J) A TELEMEDICINE SESSION CANNOT BE REJOINED: ended, no-show, and cancelled sessions never reopen \u2014 " .
                "open the consultation record instead to write or edit the SOAP notes. " .
                "(K) A LIST LOOKS EMPTY IN GENERAL: check the RHU filter, the status/board filter, the date range, and any search text \u2014 " .
                "RHU scoping is enforced on the server, so staff genuinely cannot see another RHU\'s records. " .
                "After the checklist, name the one button they should open to verify. " .
                "OPERATIONAL MATH: you SHOULD do basic arithmetic for everyday RHU tasks when the user gives you the numbers — " .
                "e.g. stock remaining after dispensing (40 − 15 = 25 units left), days until an expiry date the user states, " .
                "totals, differences, averages, and simple percentages. Show the computation in one short line so staff can double-check it. " .
                "WHAT YOU CAN AND CANNOT SEE. Be exact about this, because being vague here wastes staff time. " .
                "You CAN see three things: numbers the user types in the conversation; totals returned by your own lookup tools " .
                "(queue status, appointment counts, low stock, prescription counts, follow-up counts, facilities); " .
                "and, when it is included below, the figures currently drawn on the screen the staff member is looking at. " .
                "Use all three freely, and name the figures you are using so staff can check you. " .
                "You CANNOT see individual records: a named patient, one prescription, one consultation, one ID photo. Never guess at those. " .
                "Never invent a number, a trend or a comparison that is not in front of you. " .
                "If staff ask for a figure you genuinely do not have, say so in one line and name the button that shows it " .
                "(e.g. 'click the Inventory button for the current stock count'), then offer to work with it once they read it back. " .
                "READING ANALYTICS AND REPORTS: staff open these screens to decide something, not to admire them. " .
                "When asked about a chart, a total or a report, answer in three moves: (1) what the figures say, in plain words, naming them; " .
                "(2) what stands out: a number far above or below the rest, a barangay carrying more cases than its neighbours, " .
                "records missing a diagnosis or a contact number, follow-ups going overdue, stock about to run out; " .
                "(3) what the RHU should do about it, specifically: which barangay, which staff member, which screen to open next. " .
                "Say plainly when a figure looks normal. Inventing a concern is as unhelpful as missing one. " .
                "Health figures describe a population, never a person: never reason from a total to what is happening to one patient. " .
                "Where a number suggests a health risk, such as a rising case count or a cluster in one barangay, frame it as something " .
                "for the MHO to review under DOH protocol, not as a finding of your own. " .
                "SCHEDULING A FOLLOW-UP. When staff ask you to set up, book or draft a follow-up visit " .
                "('schedule a follow-up in two weeks', 'gawan mo ng follow-up sa isang buwan'), give the schedule as these " .
                "labelled lines, each on its own line, so the form can be filled straight from it: " .
                "Follow-up Type: Single or Range; " .
                "Follow-up Date: a concrete date on or after today, written as YYYY-MM-DD; " .
                "Follow-up Start Date and Follow-up End Date instead of a single date, when the type is Range; " .
                "Follow-up Time: a realistic clinic time such as 09:00; " .
                "Urgency: Routine, Priority or Urgent; " .
                "Reason: a short plain-language label for the reminder, a few words at most, in the words the staff member used; " .
                "SMS Reminder: Yes or No. " .
                "Write each label in bold exactly as named above, followed by a colon and the value. " .
                "Then say in one line that the clinical instructions for the patient are left blank for the clinician to write. " .
                "Do NOT write those instructions yourself, and never put a diagnosis, a medicine or a dose in the Reason line. " .
                "Work out real dates from what the staff member said: in two weeks means today plus fourteen days. " .
                "Give the day of the week alongside the date, so an accidental Sunday is obvious. " .
                "THE CLINICAL LINE, which is not negotiable. You draft and fill ADMINISTRATIVE work: events, announcements, " .
                "schedules, follow-up arrangements, appointment details, report wording, and messages to staff and residents. " .
                "You do NOT fill in, pre-select or propose anything belonging to a clinician's judgement: a diagnosis, a medicine, " .
                "a dose, a frequency, a duration, or a treatment plan. Not even as a suggestion the doctor could accept with one click: " .
                "a dose sitting ready in a form is one distracted click from being dispensed to a patient. " .
                "If asked, explain what the field is for and who fills it, then stop. " .
                "Do not compute medication doses or clinical dosages; that is clinical work for a licensed clinician. " .
                "Do not expose API keys, passwords, or secrets. " .
                "For clinical questions, do not diagnose; instruct staff to follow RHU protocol and escalate to a licensed clinician.";
        }

        /*
         * The resident assistant.
         *
         * Residents do not open this to be told to book an appointment.
         * They open it the way they would open a search engine -- "why does
         * my head hurt when I wake up" -- and if the answer is only "please
         * consult a doctor" they close it and search elsewhere, where
         * nothing knows they live in Malasiqui or that the RHU opens at
         * eight. Being useful about ordinary symptoms is what earns the
         * right to say "this one needs to be seen" and be believed.
         *
         * So it answers the question first, then offers the RHU. The shape
         * below is deliberate: what is likely going on, what can be done at
         * home today, and the specific things that mean stop reading and
         * come in. That last part is the safety rail -- not a refusal to
         * engage, but a named list of what changes the answer.
         */
        return
            "You are Ka-Agapay Mobile Health Assistant for residents of Malasiqui, Pangasinan. " .
            "You do two jobs. " .

            "FIRST, you answer everyday health questions helpfully, the way a trusted health worker would. " .
            "When someone describes a common complaint -- headache, cough, colds, fever, stomach ache, back pain, skin itch, tooth pain, period pain, tiredness -- give a real answer in this shape: " .
            "(1) one or two sentences on what commonly causes this; " .
            "(2) what they can safely do at home today, in concrete steps -- rest, fluids, warm or cold compress, proper sleeping position, eating before medicine, simple stretches; " .
            "(3) when to come to the RHU, and separately, the specific warning signs that mean go to the emergency room now. " .
            "Keep it to a few short lines under each part. Speak to the person, not about the condition. " .

            "SECOND, you help residents use the mobile app: booking appointments, checking records, viewing events, uploading ID verification, using telemedicine, joining the queue, and understanding RHU services. " .
            "When the answer is a screen in the app, name the button and say what happens after they press it. " .

            "RULES THAT DO NOT BEND. " .
            "Never name a disease as their diagnosis -- say what is common and what would need checking. " .
            "Never give a medicine dose, never tell them how many tablets to take, and never recommend a prescription drug or antibiotic; if they ask, say the RHU decides the dose after seeing them, and that paracetamol taken as printed on its own pack is the usual home option for pain or fever in adults. " .
            "Never tell anyone to stop a medicine a doctor gave them. " .
            "Be more careful, and lean towards being seen, when the person says they are pregnant, is asking about a baby or small child, or mentions diabetes, high blood pressure, TB, heart or kidney trouble. " .
            "For emergency symptoms -- chest pain, severe bleeding, difficulty breathing, stroke signs, seizures, loss of consciousness, a sudden worst-ever headache, a stiff neck with fever, or a head injury -- say to go to the nearest ER or call for help immediately, and say it first, before anything else. " .
            "If you are unsure, say the RHU should look at it. That is always a safe answer and never a wasted trip. " .

            "Close a health answer by offering the next step in the app: booking a consultation, or telemedicine if they cannot travel. Offer it once, briefly, as a help rather than a deflection.";
    }

    private function buildUserPrompt(
        string $message,
        string $audience,
        array $context,
        array $attachments = []
    ): string {
        $contextText = '';

        if (!empty($context)) {
            $safeContext = collect($context)
                ->only([
                    'current_page',
                    'current_button',
                    'role',
                    'barangay',
                    'language',
                    'ui_language',
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

        // The model has no clock. Without this it drafts events dated in the
        // past, and the Event form refuses to publish a past schedule.
        $today = now()->format('l, F j, Y');
        $dateText = "\n\nToday's date is {$today}. Any date you propose for an event, "
            . 'program, or deadline MUST be on or after this date.';

        $attachmentText = $this->attachmentInstructions($attachments);
        $preferenceText = $this->preferenceInstructions($context);
        $screenText = $this->screenInstructions($context);

        // Written reference for the screen being asked about. The model is good
        // at conversation and bad at remembering which buttons THIS system has,
        // so where the answer book covers a screen, it wins.
        $reference = '';

        if ($audience === 'staff') {
            $entry = $this->answers->find($message);

            if ($entry !== null) {
                $reference = "\n\n" . $this->answers->brief($entry);
            }
        }

        return "{$audienceText}{$dateText}{$preferenceText}{$reference}{$contextText}{$screenText}{$attachmentText}\n\nUser message:\n{$message}";
    }

    /**
     * What the person is looking at, and what to do with it.
     *
     * Staff asking about a chart want three things, in this order: what the
     * numbers say, what looks wrong, and what to do about it. An assistant
     * that only restates the figures on screen is no use -- they can already
     * see those. One that invents figures is worse than no use, so the rule
     * that it may only work from what is listed here is absolute.
     */
    private function screenInstructions(array $context): string
    {
        $screen = trim((string) ($context['screen'] ?? ''));

        if ($screen === '') {
            return '';
        }

        return "\n\nWHAT THE STAFF MEMBER IS LOOKING AT RIGHT NOW:\n{$screen}\n\n"
            . "HOW TO USE IT:\n"
            . "- When the question is about this screen, answer with THESE figures, by name and value.\n"
            . "- Say what the numbers mean in plain words, then point out anything that looks wrong: "
            . "a figure far above or below the others, a gap in the records, a backlog building up.\n"
            . "- Finish with what the RHU should actually do about it. Be specific and practical: "
            . "which barangay, which staff member, which screen to open next.\n"
            . "- Use ONLY the figures listed above. Never invent a number, a trend or a comparison "
            . "that is not there. If something needed to answer is missing, say which screen shows it.\n"
            . "- These are totals, not patients. Never guess at an individual person's situation from them.";
    }

    /**
     * What to do with a photo or a voice note.
     *
     * A picture of a rash invites exactly the thing the assistant must not
     * do, which is name a condition. The instruction is therefore not "be
     * careful" but a shape to follow: describe what is visible, say what
     * that commonly looks like, give home care, and name what would make it
     * urgent. A photo also cannot be palpated, smelled or measured, and the
     * reply says so rather than implying the picture was enough.
     */
    private function attachmentInstructions(array $attachments): string
    {
        if ($attachments === []) {
            return '';
        }

        $kinds = [];

        foreach ($attachments as $attachment) {
            $kinds[] = str_starts_with($attachment['mime'] ?? '', 'audio/') ? 'audio' : 'image';
        }

        $lines = [];

        if (in_array('audio', $kinds, true)) {
            $lines[] = 'THE USER SENT A VOICE NOTE. Listen to it and answer the question in it. '
                . 'Do not transcribe it back to them and do not comment on their voice or accent. '
                . 'If it is unclear, say which part you could not make out and ask them to repeat just that.';
        }

        if (in_array('image', $kinds, true)) {
            $lines[] = 'THE USER SENT A PHOTO. Answer in this shape: what you can see in it; '
                . 'what that commonly looks like, described as "this is often..." and never as their diagnosis; '
                . 'what to do at home today; and what would mean coming to the RHU or going to the ER. '
                . 'Say plainly that a photo cannot replace being examined -- it cannot be touched, '
                . 'and it does not show fever, pain or how fast something is changing. '
                . 'If the photo is too dark or blurred to be useful, say so and ask for another rather than guessing. '
                . 'If it shows a document rather than a body -- a prescription, a lab result, an ID -- read it out and explain it '
                . 'in plain words, but do not interpret laboratory values as a diagnosis.';
        }

        return "\n\n" . implode("\n", $lines);
    }

    /**
     * The dashboard language and simple mode are instructions to the model, not
     * something to fix afterwards: the reply itself must come back in that
     * language and at that reading level.
     */
    private function preferenceInstructions(array $context): string
    {
        $lines = [];

        /*
         * The admin sends ui_language; the mobile app sends language. Only
         * the first was read, so a resident who had set the app to
         * Pangasinan or Tagalog was answered in English every time -- the
         * setting worked everywhere except the one screen that talks back.
         */
        $language = strtolower(trim((string) (
            $context['ui_language'] ?? $context['language'] ?? ''
        )));

        $languageRule = match ($language) {
            'tag', 'tl', 'fil', 'tagalog', 'filipino' =>
                'REPLY LANGUAGE: Tagalog, magalang (gumamit ng "po" at "opo"). Keep button and menu names exactly as they appear on screen, in English.',
            'pag', 'pan', 'pangasinan', 'pangasinense' =>
                'REPLY LANGUAGE: Pangasinan (Pangasinense), the language spoken in Malasiqui. Keep button and menu names exactly as they appear on screen, in English. If you are not sure of a Pangasinan word, use the Tagalog word rather than inventing one.',
            'taglish' =>
                'REPLY LANGUAGE: Taglish, the way Malasiqui RHU staff actually speak. Keep button and menu names in English.',
            'en', 'english' => 'REPLY LANGUAGE: English, plain and short.',
            default => '',
        };

        if ($languageRule !== '') {
            $lines[] = $languageRule;
        }

        if (!empty($context['simple_mode'])) {
            $lines[] = 'READING LEVEL: this user is not comfortable with computers. Answer in at most three short sentences, '
                . 'one step at a time, in everyday words. No technical terms, no lists of options, no markdown, no asterisks and no emoji '
                . '(the reply is also read aloud). Name the one button to press and say what will happen after pressing it.';
        }

        return $lines === [] ? '' : "\n\n" . implode("\n", $lines);
    }

    /**
     * Canned answers are written in English at a normal reading level, so a
     * request for another language or for simple mode has to reach the model.
     */
    private function prefersModelReply(array $context): bool
    {
        // Same two keys as preferenceInstructions(): the canned answers are
        // English, so any other language has to reach the model.
        $language = strtolower(trim((string) (
            $context['ui_language'] ?? $context['language'] ?? ''
        )));

        return !empty($context['simple_mode'])
            || ($language !== '' && !in_array($language, ['en', 'english'], true));
    }


    /**
     * The emergency check, on its own.
     *
     * Separate from the navigation rules so it can run when those are being
     * skipped -- a message carrying a photo or a voice note, or one from a
     * user whose language the canned answers are not written in. This is the
     * one reply that must never wait on the model, so it must never be
     * bypassed along with everything else.
     */
    private function emergencyResponse(string $message): ?string
    {
        $lower = mb_strtolower($message);

        /*
         * Phrases that skip the model entirely.
         *
         * This list is checked before anything else and returns a fixed
         * answer, so a slow or unavailable model cannot delay the one reply
         * that is time-critical. It is deliberately broad: a false alarm
         * costs a trip to the hospital, a miss costs more.
         *
         * Pangasinan and Tagalog terms are here because a resident in
         * distress writes in the language they think in, not the one the
         * app is set to.
         */
        $emergencyKeywords = [
            // English
            'chest pain',
            'difficulty breathing',
            'cannot breathe',
            "can't breathe",
            'stroke',
            'seizure',
            'convulsion',
            'unconscious',
            'passed out',
            'severe bleeding',
            'heavy bleeding',
            'coughing blood',
            'vomiting blood',
            'worst headache',
            'stiff neck',
            'head injury',
            'suicide',
            'kill myself',

            // Tagalog
            'sakit dibdib',
            'masakit ang dibdib',
            'hirap huminga',
            'hindi makahinga',
            'nawalan ng malay',
            'hinimatay',
            'nangingisay',
            'malakas na dugo',
            'dumudugo nang malakas',
            'umuubo ng dugo',
            'sumusuka ng dugo',
            'matigas ang leeg',
            'magpakamatay',

            // Pangasinan
            'ansakit so pagew',
            'mairap so ingas',
            'ag makaingas',
            'naandi so liknaan',
            'ondadala',
        ];

        foreach ($emergencyKeywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                // Three languages at once, because this reply must be
                // understood without anyone first changing a setting.
                return
                    "⚠️ EMERGENCY — pumunta agad sa pinakamalapit na ospital o ER, o tumawag agad ng tulong. Huwag maghintay at huwag munang mag-book ng appointment.\n\n" .
                    "⚠️ EMERGENCY — go to the nearest hospital or ER now, or call for help immediately. Do not wait, and do not book an appointment first.\n\n" .
                    "⚠️ EMERGENCY — onla ka la ed sankaasinggeran ya ospital odino ER natan, odino mantawag ka na tulong. Ag ka manalagar.";
            }
        }

        return null;
    }
    private function ruleBasedResponse(
        string $message,
        string $audience,
        string $mode = self::MODE_OPERATIONS
    ): ?string {
        // Always first, whatever else follows.
        $emergency = $this->emergencyResponse($message);

        if ($emergency !== null) {
            return $emergency;
        }

        $lower = mb_strtolower($message);

        // Getting Started mode is a teaching conversation that has to track where
        // the learner already is. The canned navigation blurbs below cannot do
        // that, so in tutorial mode everything except the emergency guard above
        // goes to the model.
        if ($mode === self::MODE_TUTORIAL) {
            return null;
        }

        if ($audience === 'staff') {
            // Troubleshooting questions must reach the model. Without this,
            // "why isn't this appointment showing in queue" matched the plain
            // 'appointment'/'queue' keyword rules below and came back as a
            // click-here walkthrough instead of the real cause checklist.
            if ($this->isTroubleshootingIntent($lower)) {
                return null;
            }

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
            // actual draft.
            //
            // This used to be gated on a >=5 word count, which was a PROXY for
            // intent and got the decision wrong on short requests: "draft an
            // event" is 3 words, failed the gate, fell through to the generic
            // 'event' rule below and returned a static walkthrough -- the model
            // was never called at all. Intent is now decided by
            // isDraftingIntent(), so message LENGTH no longer decides anything.
            if ($this->isDraftingIntent($lower)) {
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
                    "2. Gamitin ang **Patient Registry**, **Queue**, **Appointments**, **Consultations**, **Telemedicine**, at **E-Prescription / Lab Request** buttons para sa patient-care workflow.\n" .
                    "3. Gamitin ang **Team Chat** at **Inventory** buttons para sa staff coordination at stock safety.\n" .
                    "4. Gamitin ang **Announcements** at **Events** buttons para sa CMS posts, then **SMS** para sa safe resident messaging.\n" .
                    "5. Gamitin ang **Reports**, **Analytics**, at **Heatmap Analytics** buttons para sa formal reports and planning signals.\n" .
                    "6. Gamitin ang **Feedback**, **Health Follow-up**, at **Notifications** buttons para sa resident updates and alerts.\n" .
                    "7. Tapusin sa **Registration Approvals**, **Users**, at **Settings** buttons para sa account and system control.\n\n" .
                    "Para sa full step-by-step path, i-on ang **Getting Started** sa chatbot header.";
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
            /*
             * Health questions go to the model, always.
             *
             * The navigation rules below answer "how do I book" and
             * "where are my records". They must never answer "why does my
             * head hurt", which is the question residents actually arrive
             * with. Without this guard the keyword rules win on overlap and
             * a symptom question comes back as a click-here walkthrough.
             */
            if ($this->isHealthQuestion($lower)) {
                return null;
            }

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

            /*
             * Whole words only.
             *
             * containsAny() is a substring match, and 'id' sits inside
             * "fluids", "consider", "said" and "idea". A resident asking
             * "I have a headache, should I drink more fluids?" was told how
             * to upload an ID photo. The health guard above catches that
             * particular sentence now, but the rule was wrong on its own
             * terms and would surface again on anything it did not catch.
             */
            if ($this->containsAnyWord($lower, ['id', 'verify', 'verification', 'ocr', 'upload'])) {
                return
                    "Para sa ID verification, pumunta sa Profile, piliin ang ID Verification, " .
                    "upload ng malinaw na ID photo, at hintayin ang result. Iwasan ang blur, glare, at putol na image.";
            }
        }

        return null;
    }

    /**
     * When the model cannot be reached — no key, a rate limit, a timeout — a
     * staff member asking about a screen still gets the right steps, straight
     * from the answer book, instead of an apology.
     */
    private function fallbackResponse(string $message, string $audience): string
    {
        if ($audience === 'staff') {
            $entry = $this->answers->find($message);

            if ($entry !== null) {
                return $this->answers->plainAnswer($entry);
            }
        }

        return $this->genericFallback($message, $audience);
    }

    private function genericFallback(string $message, string $audience): string
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

    /**
     * Tutorial mode is opt-in from the assistant header and only meaningful for
     * staff; residents always get the ordinary assistant.
     */
    private function resolveMode(array $context, string $audience): string
    {
        if ($audience !== 'staff') {
            return self::MODE_OPERATIONS;
        }

        return ($context['assistant_mode'] ?? null) === self::MODE_TUTORIAL
            ? self::MODE_TUTORIAL
            : self::MODE_OPERATIONS;
    }

    /**
     * "Getting Started" persona: walks a new RHU staff member through the
     * dashboard one step at a time instead of answering one-off questions.
     */
    private function tutorialSystemPrompt(): string
    {
        return
            "You are the Ka-Agapay Getting Started Coach for new RHU staff of Malasiqui, Pangasinan, inside the admin dashboard. " .
            "Your job is TEACHING, not answering one-off operational questions. Assume the person is new to the system and may have low digital confidence. " .
            "MANNER: patient, warm, encouraging, never condescending, the way a courteous Philippine government health office speaks " .
            "(gumamit ng 'po'/'opo' kapag Tagalog o Taglish ang user). Mirror the user's language (English, Tagalog, or Taglish). " .
            "IMPORTANT STYLE RULE: refer to clickable navigation items as buttons, not modules. Say 'click the Queue button'. " .
            "TEACHING METHOD - follow this every time: " .
            "1) Open with one short sentence naming what they are about to learn. " .
            "2) Give the steps as a SHORT numbered list, at most 5 steps, one action per step, each naming the exact button to click. " .
            "3) Add one 'What you should see' line so they can confirm it worked. " .
            "4) Add one short 'Watch out for' line with the mistake new staff actually make here. " .
            "5) End by asking ONE question: whether to continue to the next topic or repeat this one. Always end with that question. " .
            "Never dump the whole system at once. Teach ONE topic per reply and wait. " .
            "Ground the walkthrough in the actual admin pages and their header descriptions, not generic clinic software. Use these source descriptions when explaining each button: " .
            "Dashboard button - Real-Time RHU Dashboard: live tracking for patients, consultations, queue, telemedicine, inventory, and barangay health heatmap. " .
            "Patient Registry button - Browse and search active patients, then open a profile for full history. " .
            "Queue button - near real-time queue status across RHU stations, refreshed from the backend queue API; staff call, serve, complete, skip, no-show, and add walk-in patients here. " .
            "Appointments button - Simple RHU appointment board for approving, scheduling, rejecting, adding onsite patients to queue, and starting consultations. " .
            "Consultations button - Review active consultation records, open SOAP documentation, check diagnosis status, and make sure every consultation is properly documented before completion. " .
            "Telemedicine button - Screen RHU online consultation requests, open video sessions, track request progress, and safely complete SOAP documentation. " .
            "E-Prescription / Lab Request button - Create medicine prescriptions or laboratory requests and release official PDFs; dispensing happens after release. " .
            "Team Chat button - Internal staff messaging with chats, group conversations, search, presence, seen receipts, and voice/video calls. " .
            "Inventory button - Real-time medicines and vaccines stock tracking from the backend, including low stock, expiry, stock-in, stock-out, and adjustment history. " .
            "CMS Announcements button - Content Management: create simple, readable, and timely public information for Ka-Agapay residents; preview before publishing and archive old advisories. " .
            "Events button - Events & Programs Management: create clear RHU events, health programs, and public advisories; complete schedule, location, target audience, barangay target, RHU service, visibility, and SMS summary before publishing. " .
            "Reports button - Formal Diagnosis + ITR consultation records, follow-up tracking, data completeness, staff workload, barangay watchlist, and CSV exports. " .
            "Analytics button - Track patients, consultations, telemedicine usage, queue tickets, disease clusters, and chatbot questions for better RHU planning. " .
            "Heatmap Analytics button - Separate operational workspaces for RHU queue monitoring and barangay disease cluster surveillance. " .
            "Feedback button - Patient service feedback and condition updates submitted from the mobile app, scoped to the assigned RHU; clinical follow-up reminders stay in Health Follow-up. " .
            "Health Follow-up button - Track overdue, due today, upcoming, and completed patient follow-ups. " .
            "Notifications button - View mobile requests, queue updates, telemedicine reminders, appointment notices, RHU posts, and important system alerts in one simple inbox. " .
            "SMS Center button - Send safe RHU reminders, queue alerts, follow-ups, and program advisories; preview recipients first. " .
            "Registration Approvals button - Review pending residents and staff, open View OCR to verify submitted ID, then approve or reject. " .
            "Users button - Review, approve, edit, disable, or archive accounts safely; Super Admin can update user roles instantly. " .
            "Settings button - Manage RHU information, notifications, security, and backup settings clearly and safely. " .
            "If the user has not chosen a topic yet, briefly offer this learning path and ask which to start with: " .
            "(1) Dashboard, (2) Patient Registry, (3) Queue, (4) Appointments, (5) Consultations, (6) Telemedicine, " .
            "(7) E-Prescription / Lab Request, (8) Team Chat, (9) Inventory, (10) CMS Announcements, (11) Events & Programs, " .
            "(12) Reports, (13) Analytics, (14) Heatmap Analytics, (15) Feedback, (16) Health Follow-up, (17) Notifications, " .
            "(18) SMS Center, (19) Registration Approvals, (20) Users, (21) Settings. " .
            "You have NO access to live system data: never invent patient records, stock counts, queue numbers, or figures. " .
            "Use realistic EXAMPLE values when illustrating a step and label them clearly as examples. " .
            "Do not give clinical advice or medication doses - that is for a licensed clinician. Do not expose API keys, passwords, or secrets. " .
            "If the user asks a normal operational question while in this mode, answer it briefly and then offer to resume the walkthrough.";
    }

    /**
     * "Why is X not working / not showing / failed" style questions. These need
     * the grounded cause checklist in the system prompt, never a canned
     * navigation answer.
     */
    /**
     * "Produce this content for me" requests, which must reach the model rather
     * than a canned navigation walkthrough.
     *
     * Deliberately decided by INTENT, never by message length. The previous
     * >=5-word gate meant "draft an event" (3 words) silently fell through to
     * the generic 'event' walkthrough and never reached Gemini at all.
     *
     * Both halves are required:
     *   1. a verb that means "compose it for me", not "show me where to click"
     *   2. a publishable CMS subject to actually compose
     *
     * Requiring the subject is what keeps the weak connector words below from
     * false-positiving on short unrelated messages, and is why "make a report"
     * (a Reports-module navigation task the assistant cannot compose) keeps its
     * canned walkthrough while "draft an event" does not.
     */
    private function isDraftingIntent(string $lower): bool
    {
        $composeVerb = $this->containsAny($lower, [
            'draft', 'compose', 'write', 'create a', 'create an',
            'make a', 'make an', 'help me', 'assist me', 'suggest', 'recommend',
            'gumawa', 'gawan', 'isulat', 'buuin', 'tulungan', 'magmungkahi',
        ]);

        if (!$composeVerb) {
            return false;
        }

        return $this->containsAny($lower, [
            'event', 'announcement', 'program', 'campaign', 'advisory',
            'post', 'sms', 'anunsyo', 'patalastas', 'programa', 'kaganapan',
        ]);
    }

    private function isTroubleshootingIntent(string $lower): bool
    {
        $asksWhy = $this->containsAny($lower, [
            'why is', 'why isn', 'why are', 'why did', 'why does', 'why do',
            'why can', 'why won', 'bakit', 'anong problema', 'ano ang problema',
        ]);

        $reportsFault = $this->containsAny($lower, [
            'not showing', 'not appearing', 'not show', 'not visible',
            'does not appear', 'doesn\'t appear', 'did not appear',
            'not working', 'does not work', 'doesn\'t work', 'wont work',
            'won\'t work', 'cannot', 'can not', 'can\'t', 'unable to',
            'failed', 'failing', 'fails', 'error', 'rejected', 'blocked',
            'missing', 'empty', 'no results', 'stuck', 'troubleshoot',
            'hindi lumalabas', 'hindi makita', 'hindi gumagana', 'hindi ma',
            'walang lumalabas', 'ayaw', 'problema', 'mali',
        ]);

        return $asksWhy || $reportsFault;
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

    /**
     * Like containsAny(), but the keyword has to be a whole word.
     *
     * For short keywords a substring match is almost always wrong:
     * 'id' matches "fluids", 'ubo' matches "ubos". Use this wherever a
     * keyword is shorter than about five letters.
     */
    private function containsAnyWord(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote(mb_strtolower($keyword), '/') . '(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a resident is asking about their body rather than the app.
     *
     * Deliberately generous. Sending an app question to the model costs a
     * slightly wordier answer; sending a symptom question to the canned
     * navigation rules costs the resident a useless reply at the moment
     * they most needed a useful one, and they do not ask twice.
     *
     * Three languages, because people describe pain in the language they
     * think in whatever the app is set to.
     */
    private function isHealthQuestion(string $lower): bool
    {
        $longTerms = [
            // English
            'headache', 'head hurt', 'migraine', 'fever', 'cough', 'colds',
            'sore throat', 'stomach', 'tummy', 'diarrhea', 'diarrhoea',
            'vomit', 'nausea', 'rash', 'itchy', 'itching', 'toothache',
            'tooth pain', 'back pain', 'dizzy', 'dizziness', 'cramps',
            'period pain', 'symptom', 'remedy', 'remedies', 'painful',
            'swollen', 'swelling', 'infection', 'allergy', 'asthma',
            'blood pressure', 'sugar level', 'pregnan', 'breastfeed',

            // Tagalog
            'masakit', 'sumasakit', 'ang sakit', 'lagnat', 'ubo', 'sipon',
            'trangkaso', 'pagtatae', 'nagsusuka', 'nahihilo', 'pantal',
            'makati', 'ngipin', 'regla', 'pananakit', 'lunas', 'nilalagnat',
            'sakit ng ulo', 'sakit sa tiyan', 'gamot sa bahay', 'buntis',

            // Pangasinan
            'ansakit', 'sakit na ulo', 'petang', 'eges', 'mansakit',
        ];

        if ($this->containsAny($lower, $longTerms)) {
            return true;
        }

        // Short words that need a boundary, or they match inside others.
        if ($this->containsAnyWord($lower, ['sakit', 'ulo', 'hilo', 'pilay', 'sugat', 'pain', 'ache', 'sick', 'ill', 'flu'])) {
            return true;
        }

        /*
         * A body part plus a complaint word. "my back hurts", "masakit ang
         * likod" -- neither half is conclusive alone, both together are.
         */
        $bodyParts = ['head', 'ulo', 'chest', 'dibdib', 'pagew', 'back', 'likod',
                      'stomach', 'tiyan', 'eges', 'throat', 'lalamunan', 'ear',
                      'tenga', 'eye', 'mata', 'tooth', 'teeth', 'ngipin', 'leg',
                      'binti', 'arm', 'braso', 'knee', 'tuhod', 'skin', 'balat'];

        $complaints = ['hurt', 'ache', 'pain', 'sore', 'sakit', 'masakit'];

        return $this->containsAny($lower, $bodyParts)
            && $this->containsAny($lower, $complaints);
    }
}
