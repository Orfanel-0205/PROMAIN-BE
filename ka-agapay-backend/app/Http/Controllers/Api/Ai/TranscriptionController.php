<?php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI dictation for staff: Pangasinan, Ilocano, Tagalog, English and any mix
 * of them, which the browser's own recognition cannot do
 * (GeminiService::transcribe). The admin converts the recording to 16 kHz
 * WAV first, the one format every browser can produce and the model always
 * reads. The text comes back to the box it was dictated into, to be checked
 * before anything is saved; the recording itself is not kept.
 */
class TranscriptionController extends Controller
{
    public function __construct(private readonly GeminiService $gemini)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Two minutes of 16 kHz mono WAV is about 3.8 MB.
            'audio' => ['required', 'file', 'max:8192', 'mimetypes:audio/wav,audio/x-wav,audio/wave,audio/vnd.wave,audio/mpeg,audio/mp4,audio/x-m4a,audio/ogg,audio/webm,video/webm'],
            'hint' => ['nullable', Rule::in(['auto', 'tl', 'pag', 'ilo', 'en'])],
            'context' => ['nullable', Rule::in(['soap', 'chat', 'telemedicine'])],
        ]);

        $file = $request->file('audio');
        $mime = (string) ($file->getMimeType() ?: 'audio/wav');

        // finfo calls a WAV "audio/x-wav"; the model wants the plain name.
        if (in_array($mime, ['audio/x-wav', 'audio/wave', 'audio/vnd.wave'], true)) {
            $mime = 'audio/wav';
        }

        $result = $this->gemini->transcribe(
            (string) file_get_contents($file->getRealPath()),
            $mime,
            $validated['hint'] ?? 'auto',
            $validated['context'] ?? 'soap'
        );

        if ($result === null) {
            return response()->json([
                'message' => 'AI transcription is not available right now. Type it, or dictate in Filipino or English.',
            ], 503);
        }

        if ($result['transcript'] === '') {
            return response()->json([
                'message' => 'No speech was heard in the recording. Try again closer to the microphone.',
            ], 422);
        }

        return response()->json($result);
    }
}
