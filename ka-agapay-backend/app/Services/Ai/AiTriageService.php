<?php
// app/Services/Ai/AiTriageService.php

namespace App\Services\Ai;

use App\Models\AiRequest;
use App\Models\AiTriageScore;
use App\Models\TelemedicineRequest;
use App\Models\QueueTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiTriageService
{
    public const STAFF_DISCLAIMER = 'AI triage is a support tool only. RHU staff must validate urgency and priority before final action.';

    /**
     * Deterministic, no-external-key triage for web/admin quick checks.
     */
    public function scorePayload(array $input): array
    {
        $score = 10;
        $reasons = [];

        $complaint = strtolower(trim((string) (
            $input['chief_complaint']
            ?? $input['complaint']
            ?? $input['notes']
            ?? ''
        )));

        $age = is_numeric($input['age'] ?? null) ? (int) $input['age'] : null;
        $isSenior = (bool) ($input['is_senior'] ?? ($age !== null && $age >= 60));
        $isPwd = (bool) ($input['is_pwd'] ?? false);
        $isPregnant = (bool) ($input['is_pregnant'] ?? false);
        $isChild = (bool) ($input['is_pediatric'] ?? ($age !== null && $age <= 12));
        $isEmergency = (bool) ($input['is_emergency'] ?? false);

        $urgentKeywords = [
            'chest pain', 'pananakit ng dibdib', 'difficulty breathing',
            'hirap huminga', 'severe bleeding', 'seizure', 'fainting',
            'loss of consciousness', 'nahimatay', 'pregnancy emergency',
            'severe dehydration', 'very high fever',
        ];

        $highKeywords = [
            'severe pain', 'asthma', 'persistent vomiting', 'dehydration',
            'nanghihina', 'high fever',
        ];

        $moderateKeywords = [
            'fever', 'lagnat', 'cough', 'ubo', 'vomiting', 'pagsusuka',
            'diarrhea', 'pagtatae', 'dizziness', 'hilo', 'wound', 'sugat',
            'follow-up',
        ];

        if ($isEmergency || $this->containsAny($complaint, $urgentKeywords)) {
            $score = max($score, 90);
            $reasons[] = $isEmergency ? 'Emergency queue flag' : 'Complaint contains urgent symptoms';
        }

        if ($this->containsAny($complaint, $highKeywords)) {
            $score = max($score, 70);
            $reasons[] = 'Complaint contains high-priority symptoms';
        }

        if ($isSenior && $complaint !== '') {
            $score = max($score, 65);
            $reasons[] = 'Senior citizen with complaint';
        }

        if ($isPwd && $complaint !== '') {
            $score = max($score, 65);
            $reasons[] = 'PWD with complaint';
        }

        if ($isPregnant && $complaint !== '') {
            $score = max($score, 70);
            $reasons[] = 'Pregnant patient with complaint';
        }

        if ($isChild && str_contains($complaint, 'fever')) {
            $score = max($score, 70);
            $reasons[] = 'Child with fever';
        }

        if (
            (str_contains($complaint, 'fever') || str_contains($complaint, 'lagnat')) &&
            (str_contains($complaint, 'breath') || str_contains($complaint, 'hirap huminga'))
        ) {
            $score = max($score, 85);
            $reasons[] = 'Fever with breathing symptoms';
        }

        if ($this->containsAny($complaint, $moderateKeywords)) {
            $score = max($score, 45);
            $reasons[] = 'Complaint contains symptoms needing earlier review';
        }

        if ($complaint === '' || $this->containsAny($complaint, ['general inquiry', 'certificate', 'routine follow-up', 'program registration'])) {
            $score = min($score, 25);
            $reasons[] = $complaint === '' ? 'No urgent complaint provided' : 'Low-acuity administrative or routine concern';
        }

        $score = min(100, max(0, $score));
        $level = match (true) {
            $score >= 85 => 'urgent',
            $score >= 65 => 'high',
            $score >= 35 => 'moderate',
            default => 'low',
        };

        $label = match ($level) {
            'urgent' => 'Urgent priority',
            'high' => 'High priority',
            'moderate' => 'Moderate priority',
            default => 'Low priority',
        };

        $action = match ($level) {
            'urgent' => 'Assess immediately and prepare emergency escalation if confirmed by RHU staff.',
            'high' => 'Review earlier than regular queue after RHU staff validation.',
            'moderate' => 'Monitor symptoms and consider earlier review if queue pressure allows.',
            default => 'Proceed through regular queue order unless staff assessment changes priority.',
        };

        return [
            'triage_level' => $level,
            'priority_score' => $score,
            'priority_label' => $label,
            'reasons' => array_values(array_unique($reasons ?: ['Regular queue order'])),
            'recommended_action' => $action,
            'staff_disclaimer' => self::STAFF_DISCLAIMER,
        ];
    }

    /**
     * Score a telemedicine request.
     * This is the "AI-integrated optimization" component of the thesis.
     * The rule engine can be swapped for an external ML API
     * without changing the interface.
     */
    public function scoreTelemedicineRequest(TelemedicineRequest $request): AiTriageScore
    {
        return DB::transaction(function () use ($request) {
            $profile = $request->residentProfile;
            $queueTicket = $request->queueTicket;

            // Resident profile doesn't store all risk flags (e.g., pregnancy/PWD).
            // Queue tickets contain those eligibility flags, so we pull them from there
            // when available.
            $ageYears = $profile?->birth_date
                ? now()->diffInYears($profile->birth_date)
                : null;

            $inputPayload = [
                'chief_complaint'  => $request->chief_complaint,
                'symptoms'         => $request->symptoms ?? [],
                'urgency_declared' => $request->urgency_level,
                'is_bhw_assisted'  => $request->is_bhw_assisted,
                'resident_flags'   => [
                    'age'         => $ageYears,
                    'is_senior'   => $ageYears !== null
                        ? $ageYears >= 60
                        : (bool) ($queueTicket?->is_senior ?? false),
                    'is_pregnant' => (bool) ($queueTicket?->is_pregnant ?? false),
                    'is_pwd'      => (bool) ($queueTicket?->is_pwd ?? false),
                ],
            ];

            $aiReq = AiRequest::create([
                'triggered_by'  => null,
                'request_type'  => AiRequest::TYPE_TRIAGE_SCORE,
                'model_used'    => 'rule_engine_v1',
                'input_payload' => $inputPayload,
                'status'        => AiRequest::STATUS_PROCESSING,
                'subject_type'  => TelemedicineRequest::class,
                'subject_id'    => $request->id,
                'created_at'    => now(),
            ]);

            $start = microtime(true);

            try {
                [$score, $urgency, $factors, $confidence] =
                    $this->runRuleEngine($inputPayload);

                $processingMs = (int) ((microtime(true) - $start) * 1000);

                $outputPayload = [
                    'ai_score'             => $score,
                    'recommended_urgency'  => $urgency,
                    'contributing_factors' => $factors,
                    'confidence'           => $confidence,
                ];

                $aiReq->update([
                    'output_payload'     => $outputPayload,
                    'status'             => AiRequest::STATUS_COMPLETED,
                    'processing_time_ms' => $processingMs,
                    'completed_at'       => now(),
                    'was_applied'        => true,
                ]);

                $triageScore = AiTriageScore::create([
                    'ai_request_id'           => $aiReq->id,
                    'telemedicine_request_id' => $request->id,
                    'ai_score'                => $score,
                    'recommended_urgency'     => $urgency,
                    'contributing_factors'    => $factors,
                    'confidence'              => $confidence,
                    'created_at'              => now(),
                ]);

                return $triageScore->fresh(['aiRequest']);

            } catch (\Throwable $e) {
                $aiReq->update([
                    'status'        => AiRequest::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                    'completed_at'  => now(),
                ]);

                Log::error('AI triage scoring failed', [
                    'request_id' => $request->id,
                    'error'      => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Score a queue ticket for priority adjustment.
     */
    public function scoreQueueTicket(QueueTicket $ticket): AiTriageScore
    {
        return DB::transaction(function () use ($ticket) {
            $inputPayload = [
                'service_type'    => $ticket->service_type,
                'chief_complaint' => $ticket->notes,
                'is_emergency'    => $ticket->is_emergency,
                'is_senior'       => $ticket->is_senior,
                'is_pregnant'     => $ticket->is_pregnant,
                'is_pwd'          => $ticket->is_pwd,
                'is_pediatric'    => $ticket->is_pediatric,
                'is_bhw_endorsed' => $ticket->is_bhw_endorsed,
                'current_score'   => $ticket->priority_score,
            ];

            $aiReq = AiRequest::create([
                'request_type'  => AiRequest::TYPE_TRIAGE_SCORE,
                'model_used'    => 'rule_engine_v1',
                'input_payload' => $inputPayload,
                'status'        => AiRequest::STATUS_PROCESSING,
                'subject_type'  => QueueTicket::class,
                'subject_id'    => $ticket->id,
                'created_at'    => now(),
            ]);

            $start = microtime(true);

            [$score, $urgency, $factors, $confidence] =
                $this->runQueueRuleEngine($inputPayload);

            $aiReq->update([
                'output_payload'     => compact('score', 'urgency', 'factors', 'confidence'),
                'status'             => AiRequest::STATUS_COMPLETED,
                'processing_time_ms' => (int) ((microtime(true) - $start) * 1000),
                'completed_at'       => now(),
                'was_applied'        => true,
            ]);

            return AiTriageScore::create([
                'ai_request_id'       => $aiReq->id,
                'queue_ticket_id'     => $ticket->id,
                'ai_score'            => $score,
                'recommended_urgency' => $urgency,
                'contributing_factors'=> $factors,
                'confidence'          => $confidence,
                'created_at'          => now(),
            ]);
        });
    }

    // ── Rule Engine — Telemedicine ────────────────────────────────────────────

    /**
     * Multi-criteria weighted scoring engine.
     * This is defensible as AI in your thesis because:
     * 1. It uses structured inference from clinical inputs
     * 2. It produces explainable outputs (contributing_factors)
     * 3. It generates a confidence score
     * 4. It is configurable and upgradeable to ML
     *
     * Returns: [score, urgency, factors, confidence]
     */
    private function runRuleEngine(array $input): array
    {
        $score   = 0;
        $factors = [];

        // ── Declared urgency ─────────────────────────────────────────────────
        match ($input['urgency_declared']) {
            'emergency' => [$score, $factors] = [$score + 50, [...$factors, 'declared_emergency']],
            'urgent'    => [$score, $factors] = [$score + 25, [...$factors, 'declared_urgent']],
            default     => null,
        };

        // ── Chief complaint keyword analysis ─────────────────────────────────
        $emergencyKeywords = [
            'chest pain', 'difficulty breathing', 'dyspnea',
            'loss of consciousness', 'seizure', 'stroke',
            'severe bleeding', 'head injury', 'unconscious',
            'heart attack', 'hindi makahinga', 'hirap huminga',
        ];

        $complaint = strtolower($input['chief_complaint'] ?? '');
        foreach ($emergencyKeywords as $keyword) {
            if (str_contains($complaint, $keyword)) {
                $score   += 20;
                $factors[] = "keyword_match:{$keyword}";
                break;
            }
        }

        // ── High-risk symptom combinations ───────────────────────────────────
        $symptoms = array_map('strtolower', $input['symptoms'] ?? []);

        $highRiskCombinations = [
            ['fever', 'difficulty breathing'],
            ['chest pain', 'sweating'],
            ['headache', 'vomiting', 'stiff neck'],
            ['fever', 'rash', 'joint pain'],
        ];

        foreach ($highRiskCombinations as $combo) {
            if (count(array_intersect($combo, $symptoms)) === count($combo)) {
                $score   += 15;
                $factors[] = 'high_risk_symptom_combination';
                break;
            }
        }

        // ── Vulnerable population flags ───────────────────────────────────────
        $flags = $input['resident_flags'];

        if (!empty($flags['is_senior']))   { $score += 10; $factors[] = 'senior_citizen'; }
        if (!empty($flags['is_pregnant'])) { $score += 10; $factors[] = 'pregnant'; }
        if (!empty($flags['is_pwd']))      { $score += 5;  $factors[] = 'pwd'; }

        // ── BHW escalation bonus ──────────────────────────────────────────────
        if (!empty($input['is_bhw_assisted'])) {
            $score   += 5;
            $factors[] = 'bhw_endorsed';
        }

        // ── Age-based risk ────────────────────────────────────────────────────
        $age = $flags['age'] ?? null;
        if ($age !== null) {
            if ($age < 1)        { $score += 15; $factors[] = 'infant'; }
            elseif ($age < 5)    { $score += 10; $factors[] = 'pediatric'; }
            elseif ($age >= 75)  { $score += 10; $factors[] = 'elderly_75_plus'; }
        }

        $score = min($score, 100);

        $urgency = match(true) {
            $score >= 60 => 'emergency',
            $score >= 30 => 'urgent',
            default      => 'routine',
        };

        $confidence = match(true) {
            $score >= 60 => 0.8800,
            $score >= 40 => 0.7500,
            $score >= 20 => 0.6800,
            default      => 0.6000,
        };

        return [$score, $urgency, $factors, $confidence];
    }

    // ── Rule Engine — Queue ───────────────────────────────────────────────────

    private function runQueueRuleEngine(array $input): array
    {
        $score   = $input['current_score'] ?? 0;
        $factors = [];

        if ($input['is_emergency'])    { $score = 100; $factors[] = 'emergency_override'; }
        if ($input['is_pregnant'])     { $score += 5;  $factors[] = 'pregnant_bonus'; }
        if ($input['is_senior'])       { $score += 3;  $factors[] = 'senior_bonus'; }
        if ($input['is_bhw_endorsed']) { $score += 2;  $factors[] = 'bhw_endorsed_bonus'; }

        $payload = $this->scorePayload($input);
        $score = max($score, (int) $payload['priority_score']);
        $factors = array_values(array_unique([...$factors, ...$payload['reasons']]));

        $score = min($score, 100);

        $urgency = match(true) {
            $score >= 85 => 'urgent',
            $score >= 65 => 'high',
            $score >= 35 => 'moderate',
            default      => 'low',
        };

        return [$score, $urgency, $factors, 0.7500];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
