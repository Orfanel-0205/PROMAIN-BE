<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey;
    // Switched to gemini-1.5-flash — more generous free tier quota
    private string $model   = 'gemini-1.5-flash';
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    private string $systemPrompt = <<<PROMPT
        You are "Ka-Agapay AI", a friendly and empathetic health assistant for the Rural Health Unit (RHU) of Malasiqui, Pangasinan, Philippines.

        LANGUAGE: Always respond in the same language the user uses.
        - If they write in Tagalog → respond in Tagalog
        - If they write in Ilocano → respond in Ilocano
        - If they write in English → respond in English
        - Mix languages naturally if the user does (Taglish is fine)

        YOUR ROLE:
        - Help citizens understand basic health symptoms
        - Guide them to book appointments at RHU1 or RHU2 Malasiqui when appropriate
        - Explain health programs and services available at the RHU
        - Provide basic first aid advice for minor concerns
        - Always recommend seeing a doctor for serious symptoms

        IMPORTANT RULES:
        - Never diagnose medical conditions definitively
        - Always recommend consulting an RHU doctor for serious concerns
        - Keep responses concise (3-5 sentences max unless explaining a procedure)
        - Be warm, caring, and use simple language appropriate for all ages
        - For emergencies (chest pain, difficulty breathing, severe bleeding), immediately advise going to the nearest hospital or calling emergency services

        ABOUT RHU MALASIQUI:
        - Located in Malasiqui, Pangasinan
        - Serves residents of Malasiqui and surrounding barangays
        - Offers free basic consultations, maternal care, immunizations, and medicines
    PROMPT;

    public function __construct()
    {
        $this->apiKey = config('services.google.gemini_api_key')
            ?: config('services.gemini.api_key')
            ?: env('GEMINI_API_KEY', '');

        if (empty($this->apiKey)) {
            Log::error('GeminiService: GEMINI_API_KEY is empty. Check .env and run: php artisan config:clear');
        }
    }

    public function chat(string $message, array $history = []): string
    {
        if (empty($this->apiKey)) {
            return 'Hindi pa available ang AI assistant. Makipag-ugnayan sa RHU para sa tulong.';
        }

        try {
            $contents = [];

            foreach ($history as $msg) {
                $role = ($msg['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
                $text = $msg['content'] ?? '';
                if (!empty($text)) {
                    $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
                }
            }

            $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

            $url = "{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}";

            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, [
                    'contents'          => $contents,
                    'systemInstruction' => [
                        'parts' => [['text' => $this->systemPrompt]],
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.7,
                        'topK'            => 40,
                        'topP'            => 0.95,
                        'maxOutputTokens' => 512,
                    ],
                    'safetySettings' => [
                        ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                        ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                    ],
                ]);

            if (!$response->successful()) {
                $status    = $response->status();
                $errorBody = $response->json();
                $grpcStatus = $errorBody['error']['status'] ?? '';

                Log::error('GeminiService: HTTP error', [
                    'status' => $status,
                    'body'   => $errorBody,
                    'model'  => $this->model,
                ]);

                // Quota exhausted — give a clear, user-friendly message
                if ($status === 429 || $grpcStatus === 'RESOURCE_EXHAUSTED') {
                    return 'Paumanhin, maraming gumagamit ng AI assistant ngayon. Pakisubukan ulit pagkatapos ng ilang minuto.';
                }

                return 'Paumanhin, may problema sa AI assistant. Subukan ulit mamaya o direktang pumunta sa RHU.';
            }

            $finishReason = $response->json('candidates.0.finishReason');
            if ($finishReason === 'SAFETY') {
                return 'Paumanhin, hindi ko masagot ang tanong na iyon. Makipag-ugnayan sa RHU para sa tulong.';
            }

            $text = $response->json('candidates.0.content.parts.0.text');

            if (empty($text)) {
                Log::warning('GeminiService: empty text in response', ['body' => $response->json()]);
                return 'Hindi ako makagawa ng sagot ngayon. Pakisubukan ulit.';
            }

            return trim($text);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('GeminiService: connection error', ['message' => $e->getMessage()]);
            return 'Hindi ako makakonekta sa AI service. Suriin ang internet connection at subukan ulit.';
        } catch (\Throwable $e) {
            Log::error('GeminiService: unexpected error', ['message' => $e->getMessage()]);
            return 'May hindi inaasahang problema. Pakisubukan ulit mamaya.';
        }
    }
}