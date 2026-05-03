<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-lite-latest:generateContent';




    public function __construct()
    {
        $this->apiKey = config('services.google.gemini_api_key');
    }

    /**
     * Send a message to Gemini and get a response.
     */
    public function chat(string $message, array $history = []): string
    {
        try {
            $contents = [];

            // Add history
            foreach ($history as $msg) {
                $contents[] = [
                    'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $msg['content']]],
                ];
            }

            // Add current message
            $contents[] = [
                'role'  => 'user',
                'parts' => [['text' => $message]],
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}?key={$this->apiKey}", [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.7,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 1024,
                ],
                'systemInstruction' => [
                    'parts' => [
                        ['text' => "You are 'Ka-agapay-Al', a helpful medical assistant for RHU Malasiqui. 
                        Provide concise, accurate, and empathetic health information. 
                        If asked about specific medical conditions, always advise consulting a doctor at RHU1 or RHU2.
                        Keep answers brief and helpful for citizens of Malasiqui, Pangasinan."]
                    ]
                ]

            ]);

            if (!$response->successful()) {
                Log::error('Gemini API Error', ['body' => $response->body()]);
                return "I'm sorry, I'm having trouble connecting to my brain right now. Please try again later.";
            }

            return $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? "I couldn't generate a response.";

        } catch (\Throwable $e) {
            Log::error('Gemini Service Exception', ['message' => $e->getMessage()]);
            return "An error occurred while processing your request.";
        }
    }
}
