<?php
// app/Http/Controllers/Api/Analytics/AnalyticsInsightController.php

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Ai\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Commentary on the numbers a staff member is looking at.
 *
 * The Analytics page used to label its own fixed strings "AI insight". They
 * were the same sentence whatever the figures said, which does not survive the
 * question "which model wrote that?". Those are relabelled as what they are;
 * this endpoint is the part that genuinely asks a model.
 *
 * WHAT IS SENT
 * ------------
 * Only the aggregates the page has already drawn: labelled totals, the period,
 * the facility, and the barangay risk ranking. Never a patient, a record, or a
 * row of a list -- the same rule src/lib/screenContext.ts follows, for the same
 * reason. A figure that is already on a chart on a staff member's screen is not
 * made more sensitive by being described.
 *
 * WHY IT IS A BUTTON, NOT AUTOMATIC
 * ---------------------------------
 * Generating on every page load would spend a model call each time anyone
 * opened Analytics, including the dozen times a day someone opens it to read
 * one number. Staff ask for this when they want it.
 */
class AnalyticsInsightController extends Controller
{
    public function __construct(private readonly GeminiService $gemini)
    {
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'scope' => ['nullable', 'string', 'max:160'],

            'figures' => ['required', 'array', 'min:1', 'max:30'],
            'figures.*.label' => ['required', 'string', 'max:120'],
            'figures.*.value' => ['required', 'string', 'max:60'],

            'notes' => ['nullable', 'array', 'max:12'],
            'notes.*' => ['nullable', 'string', 'max:200'],
        ]);

        /*
         * One answer per set of numbers, for fifteen minutes.
         *
         * Three staff opening the same dashboard on the same morning are
         * looking at identical figures, and asking three times would spend
         * three model calls to produce three near-identical paragraphs. The key
         * is the figures themselves, so the moment a number moves the answer is
         * generated afresh.
         */
        // v2: answers cached before English was pinned would otherwise
        // keep being served in whichever language they were written in.
        $cacheKey = 'analytics.insight.v2.' . md5(json_encode([
            $validated['scope'] ?? '',
            $validated['figures'],
            $validated['notes'] ?? [],
        ]));

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return response()->json([
                'insight' => $cached,
                'generated_at' => Cache::get($cacheKey . '.at'),
                'cached' => true,
            ]);
        }

        $reply = $this->gemini->chat(
            $this->prompt($validated),
            [],
            'staff',
            [
                'source' => 'analytics',
                'current_page' => 'Analytics',
                // Deliberately not forwarded. The dashboard language
                // setting governs the interface; these briefings are
                // written in English because reports are.
                'ui_language' => 'en',
            ],
            $request->user()
        );

        $reply = trim($reply);

        if ($reply === '') {
            return response()->json([
                'message' => 'The assistant could not read these figures just now. Try again shortly.',
            ], 503);
        }

        $now = now()->toIso8601String();

        Cache::put($cacheKey, $reply, now()->addMinutes(15));
        Cache::put($cacheKey . '.at', $now, now()->addMinutes(15));

        return response()->json([
            'insight' => $reply,
            'generated_at' => $now,
            'cached' => false,
        ]);
    }

    /**
     * The figures, written out for the model.
     *
     * Plain labelled lines rather than JSON: it reads the same to the model and
     * it stays readable in a log when somebody asks why the assistant said what
     * it said.
     */
    private function prompt(array $data): string
    {
        $lines = [];

        foreach ($data['figures'] as $figure) {
            $lines[] = "- {$figure['label']}: {$figure['value']}";
        }

        $figures = implode("\n", $lines);

        $notes = '';

        if (!empty($data['notes'])) {
            $noteLines = array_map(fn ($note) => "- {$note}", array_filter($data['notes']));

            if ($noteLines !== []) {
                $notes = "\n\nBarangay risk ranking:\n" . implode("\n", $noteLines);
            }
        }

        $scope = $data['scope'] ?? 'the current period';

        return <<<PROMPT
        You are reading the Ka-Agapay analytics screen with an RHU staff member.

        Period and facility: {$scope}

        Figures currently on screen:
        {$figures}{$notes}

        Write a short briefing on THESE numbers, in at most 150 words, as three
        short paragraphs with no headings, no bullet points and no markdown.

        1. What stands out. Name the actual figures. If the numbers are too few
           to conclude anything, say so plainly rather than inventing a trend --
           a handful of visits over a few days is not a pattern.
        2. What it probably means for the RHU, in operational terms: staffing,
           stock, follow-up, which barangay to look at.
        3. One thing to do next, specific enough to act on this week.

        Do not repeat the figures back as a list. Do not congratulate anyone. Do
        not speculate about individual patients -- you are seeing totals only.
        Do not recommend clinical treatment; this is service planning.

        Write in English, whatever language the question appears to be in.
        These briefings are read alongside reports that go to the Municipal
        Health Office and the DOH, and those are written in English.
        PROMPT;
    }
}
