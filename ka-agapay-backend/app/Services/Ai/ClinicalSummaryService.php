<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClinicalSummaryService
{
    public function summarize(array $input): array
    {
        $transcript = $this->cleanTranscript((string) ($input['transcript'] ?? ''));

        $chiefComplaint = trim((string) ($input['chief_complaint'] ?? ''));
        $subjective = trim((string) ($input['subjective'] ?? ''));
        $objective = trim((string) ($input['objective'] ?? ''));
        $assessment = trim((string) ($input['assessment'] ?? ''));
        $plan = trim((string) ($input['plan'] ?? ''));
        $diagnosis = trim((string) ($input['diagnosis'] ?? ''));
        $treatment = trim((string) ($input['treatment'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));

        $sourceText = trim(implode("\n", array_filter([
            $chiefComplaint ? "Chief complaint: {$chiefComplaint}" : null,
            $transcript ? "Transcript: {$transcript}" : null,
            $subjective ? "Existing subjective: {$subjective}" : null,
            $objective ? "Existing objective: {$objective}" : null,
            $assessment ? "Existing assessment: {$assessment}" : null,
            $plan ? "Existing plan: {$plan}" : null,
            $diagnosis ? "Existing diagnosis: {$diagnosis}" : null,
            $treatment ? "Existing treatment: {$treatment}" : null,
            $notes ? "Existing notes: {$notes}" : null,
        ])));

        if ($sourceText === '') {
            return $this->fallbackSummary('', $chiefComplaint);
        }

        $apiKey = config('services.google.gemini_api_key') ?: env('GEMINI_API_KEY');

        if (!$apiKey) {
            return $this->fallbackSummary($sourceText, $chiefComplaint);
        }

        try {
            $prompt = <<<PROMPT
You are assisting RHU medical staff in the Philippines.

Convert the consultation transcript/notes into a structured SOAP clinical summary.

Important rules:
- Do not invent symptoms, medicines, diagnosis, vital signs, age, gender, or findings.
- Use only details explicitly found in the transcript or notes.
- If a section has no information, write "Not stated".
- Keep the wording professional and concise.
- Return valid JSON only.
- Do not include markdown fences.

Input:
{$sourceText}

Return exactly this JSON structure:
{
  "subjective": "",
  "objective": "",
  "assessment": "",
  "plan": "",
  "diagnosis": "",
  "treatment": "",
  "summary": "",
  "confidence": 0
}
PROMPT;

            $response = Http::timeout(45)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => 1200,
                    ],
                ]
            );

            if (!$response->successful()) {
                Log::warning('Gemini clinical summary failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return $this->fallbackSummary($sourceText, $chiefComplaint);
            }

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

            if (!$text) {
                return $this->fallbackSummary($sourceText, $chiefComplaint);
            }

            $json = $this->parseJsonFromAiText($text);

            if (!is_array($json)) {
                return $this->fallbackSummary($sourceText, $chiefComplaint);
            }

            return [
                'subjective' => $this->safeValue($json['subjective'] ?? null),
                'objective'  => $this->safeValue($json['objective'] ?? null),
                'assessment' => $this->safeValue($json['assessment'] ?? null),
                'plan'       => $this->safeValue($json['plan'] ?? null),
                'diagnosis'  => $this->safeValue($json['diagnosis'] ?? null),
                'treatment'  => $this->safeValue($json['treatment'] ?? null),
                'summary'    => $this->safeValue($json['summary'] ?? null),
                'confidence' => is_numeric($json['confidence'] ?? null)
                    ? max(0, min(100, (int) $json['confidence']))
                    : 80,
                'source' => 'gemini',
            ];
        } catch (\Throwable $e) {
            Log::warning('AI clinical summary exception.', [
                'message' => $e->getMessage(),
            ]);

            return $this->fallbackSummary($sourceText, $chiefComplaint);
        }
    }

    private function parseJsonFromAiText(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/^```\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function cleanTranscript(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $words = explode(' ', $text);
        $cleanWords = [];
        $lastWord = null;
        $repeatCount = 0;

        foreach ($words as $word) {
            $word = trim($word);

            if ($word === '') {
                continue;
            }

            if ($word === $lastWord) {
                $repeatCount++;

                if ($repeatCount >= 2) {
                    continue;
                }
            } else {
                $repeatCount = 0;
            }

            $cleanWords[] = $word;
            $lastWord = $word;
        }

        $cleaned = implode(' ', $cleanWords);

        $phrasesToReduce = [
            'hello thank you i see here that you book consultation',
            'hello thank you i see here',
            'hello thank you',
            'i see here',
        ];

        foreach ($phrasesToReduce as $phrase) {
            $cleaned = preg_replace(
                '/(' . preg_quote($phrase, '/') . '\s*){2,}/',
                $phrase . ' ',
                $cleaned
            );
        }

        return trim($cleaned);
    }

    private function fallbackSummary(string $text, ?string $chiefComplaint = null): array
    {
        $lowerText = strtolower($text);

        $subjectiveParts = [];

        if ($chiefComplaint) {
            $subjectiveParts[] = "Chief complaint: {$chiefComplaint}.";
        }

        if ($text) {
            $subjectiveParts[] = $text;
        }

        $objective = 'Not stated';

        if (preg_match('/temperature\s*(is|of)?\s*([0-9.]+)/i', $text, $match)) {
            $objective = 'Temperature: ' . $match[2] . '°C.';
        }

        $assessment = 'Not stated';

        if (str_contains($lowerText, 'fatigue') || str_contains($lowerText, 'dehydration')) {
            $assessment = 'Possible fatigue or dehydration based on transcript.';
        } elseif (str_contains($lowerText, 'fever') || str_contains($lowerText, 'cough')) {
            $assessment = 'Possible acute respiratory or febrile illness based on transcript.';
        }

        $plan = 'Advise patient to follow RHU staff instructions and return for reassessment if symptoms worsen.';

        if (str_contains($lowerText, 'drink plenty of water') || str_contains($lowerText, 'dehydration')) {
            $plan = 'Encourage adequate fluid intake. Monitor symptoms and seek consultation if condition worsens.';
        }

        return [
            'subjective' => trim(implode(' ', $subjectiveParts)) ?: 'Not stated',
            'objective'  => $objective,
            'assessment' => $assessment,
            'plan'       => $plan,
            'diagnosis'  => $assessment,
            'treatment'  => $plan,
            'summary'    => "SOAP summary generated from available consultation text. Assessment: {$assessment} Plan: {$plan}",
            'confidence' => 50,
            'source'     => 'fallback',
        ];
    }

    private function safeValue(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : 'Not stated';
    }
}