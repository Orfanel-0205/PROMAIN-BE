<?php
// app/Services/Queue/QueuePrioritizationService.php

namespace App\Services\Queue;

use App\Models\Barangay;
use App\Models\Consultation;
use App\Models\QueuePriorityRule;
use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid Queue Prioritization Engine
 * ===================================
 *
 * Implements a multi-criteria, weighted scoring algorithm that computes
 * healthcare queue priority scores (1–100) using the formula:
 *
 *   Q_score = S_complaint + W_demographic + W_chronic + W_barangay + W_congestion + W_escalation
 *
 * Design principles:
 *   - Deterministic:  identical inputs always produce identical scores.
 *   - Explainable:    every point is attributed to a named factor in the breakdown.
 *   - Auditable:      the full breakdown is persisted alongside the ticket.
 *   - Healthcare-safe: rule-based core; AI only assists severity interpretation.
 *   - Anti-starvation: progressive wait-time bonus prevents indefinite queueing.
 *
 * DSA Concept — Max-Heap Priority Queue:
 *   Patients are served in descending priority_score order. When scores are
 *   equal, FIFO on issued_at provides the tiebreaker, effectively
 *   implementing a stable max-heap without an in-memory data structure
 *   (PostgreSQL ORDER BY priority_score DESC, issued_at ASC).
 *
 * OOP Pattern — Strategy:
 *   Scoring weights are loaded from the database (queue_priority_rules),
 *   allowing RHU administrators to adjust weights without code changes.
 */
class QueuePrioritizationService
{
    // ── Default weights (used when DB rules are unavailable) ──────────────
    private const DEFAULT_WEIGHTS = [
        'is_emergency'    => 50,
        'is_pregnant'     => 25,
        'is_senior'       => 20,
        'is_pwd'          => 20,
        'is_pediatric'    => 15,
        'is_bhw_endorsed' => 10,
    ];

    private const CHRONIC_WEIGHT             = 20;
    private const BARANGAY_RISK_CRITICAL     = 10;
    private const BARANGAY_RISK_HIGH         = 7;
    private const BARANGAY_RISK_MODERATE     = 4;
    private const TELEMEDICINE_ESCALATION    = 15;
    private const DEMOGRAPHIC_CAP            = 45;
    private const ANTI_STARVATION_THRESHOLD  = 30; // minutes before bonus kicks in
    private const ANTI_STARVATION_RATE       = 0.5; // points per minute over threshold

    /**
     * Compute a comprehensive, explainable priority score for a patient.
     *
     * @param  ResidentProfile  $profile   The patient's resident profile.
     * @param  array            $context   Contextual flags from the ticket/request:
     *   - severity_score      (int)    AI triage severity (0–50), default 0
     *   - is_emergency        (bool)   Emergency override flag
     *   - is_pregnant         (bool)   Pregnancy status
     *   - is_pwd              (bool)   Person With Disability
     *   - is_bhw_endorsed     (bool)   BHW-endorsed referral
     *   - is_telemedicine_escalation (bool) Escalated from virtual triage
     *
     * @return array{
     *   priority_score: int,
     *   priority_category: string,
     *   queue_type: string,
     *   breakdown: array<string, int|float>,
     *   contributing_factors: list<string>
     * }
     */
    public function computePriorityScore(ResidentProfile $profile, array $context = []): array
    {
        $score   = 0;
        $breakdown = [];
        $factors = [];

        // Load configurable weights from DB with in-process cache
        $rules = $this->loadActiveRules();

        // ── 1. Chief Complaint Severity (from AiTriageService) ───────────
        $severity = min(50, (int) ($context['severity_score'] ?? 0));
        if ($severity > 0) {
            $score += $severity;
            $breakdown['chief_complaint_severity'] = $severity;
            $factors[] = 'complaint_severity_' . $severity;
        }

        // ── 2. Emergency Override ─────────────────────────────────────────
        $isEmergency = (bool) ($context['is_emergency'] ?? false);
        if ($isEmergency) {
            $emergencyWeight = $rules['is_emergency'] ?? self::DEFAULT_WEIGHTS['is_emergency'];
            $score += $emergencyWeight;
            $breakdown['emergency'] = $emergencyWeight;
            $factors[] = 'emergency_case';
        }

        // ── 3. Demographic Weighting (capped to prevent queue jumping) ───
        $demographicWeight = 0;
        $age = $profile->birth_date
            ? now()->diffInYears($profile->birth_date)
            : null;

        if ($age !== null && $age >= 60) {
            $w = $rules['is_senior'] ?? self::DEFAULT_WEIGHTS['is_senior'];
            $demographicWeight += $w;
            $breakdown['senior_citizen'] = $w;
            $factors[] = 'senior_citizen';
        }

        if (!empty($context['is_pregnant'])) {
            $w = $rules['is_pregnant'] ?? self::DEFAULT_WEIGHTS['is_pregnant'];
            $demographicWeight += $w;
            $breakdown['pregnant'] = $w;
            $factors[] = 'pregnant';
        }

        if (!empty($context['is_pwd'])) {
            $w = $rules['is_pwd'] ?? self::DEFAULT_WEIGHTS['is_pwd'];
            $demographicWeight += $w;
            $breakdown['pwd'] = $w;
            $factors[] = 'pwd';
        }

        if ($age !== null && $age < 5) {
            $w = $rules['is_pediatric'] ?? self::DEFAULT_WEIGHTS['is_pediatric'];
            $demographicWeight += $w;
            $breakdown['pediatric'] = $w;
            $factors[] = 'pediatric';
        }

        // Cap demographic total to ensure walk-in fairness
        $demographicWeight = min(self::DEMOGRAPHIC_CAP, $demographicWeight);
        $score += $demographicWeight;
        $breakdown['demographic_total'] = $demographicWeight;

        // ── 4. BHW Endorsement ────────────────────────────────────────────
        if (!empty($context['is_bhw_endorsed'])) {
            $w = $rules['is_bhw_endorsed'] ?? self::DEFAULT_WEIGHTS['is_bhw_endorsed'];
            $score += $w;
            $breakdown['bhw_endorsed'] = $w;
            $factors[] = 'bhw_endorsed';
        }

        // ── 5. Chronic Illness History ────────────────────────────────────
        if ($this->hasChronicIllnessHistory($profile->user_id)) {
            $score += self::CHRONIC_WEIGHT;
            $breakdown['chronic_illness'] = self::CHRONIC_WEIGHT;
            $factors[] = 'chronic_illness_history';
        }

        // ── 6. Barangay Disease Risk Factor ───────────────────────────────
        $barangayRisk = $this->computeBarangayRiskWeight($profile->barangay_id);
        if ($barangayRisk > 0) {
            $score += $barangayRisk;
            $breakdown['barangay_risk'] = $barangayRisk;
            $factors[] = 'high_risk_barangay';
        }

        // ── 7. Telemedicine Escalation ────────────────────────────────────
        if (!empty($context['is_telemedicine_escalation'])) {
            $score += self::TELEMEDICINE_ESCALATION;
            $breakdown['telemedicine_escalation'] = self::TELEMEDICINE_ESCALATION;
            $factors[] = 'telemedicine_escalation';
        }

        // ── Final score and classification ────────────────────────────────
        $finalScore = min(100, $score);
        $category   = $this->classifyPriority($finalScore, $isEmergency);
        $queueType  = $this->resolveQueueType($context, $age);

        return [
            'priority_score'       => $finalScore,
            'priority_category'    => $category,
            'queue_type'           => $queueType,
            'breakdown'            => $breakdown,
            'contributing_factors' => $factors,
        ];
    }

    /**
     * Anti-starvation algorithm — Progressive Dynamic Weighting.
     *
     * Prevents low-priority patients from waiting indefinitely during
     * high emergency loads. Adds +0.5 points per minute for tickets
     * waiting longer than 30 minutes.
     *
     * This implements the fairness guarantee between walk-in and
     * pre-registered patients described in the thesis objectives.
     *
     * @param  int   $rhuId  The RHU facility ID.
     * @return int   Number of tickets whose scores were adjusted.
     */
    public function applyAntiStarvation(int $rhuId): int
    {
        $adjusted = 0;

        $tickets = QueueTicket::where('rhu_id', $rhuId)
            ->where('status', 'waiting')
            ->whereDate('issued_at', today())
            ->get();

        foreach ($tickets as $ticket) {
            $minutesWaiting = now()->diffInMinutes($ticket->issued_at);

            if ($minutesWaiting > self::ANTI_STARVATION_THRESHOLD) {
                $overMinutes = $minutesWaiting - self::ANTI_STARVATION_THRESHOLD;
                $bonus       = (int) ($overMinutes * self::ANTI_STARVATION_RATE);
                $newScore    = min(95, $ticket->priority_score + $bonus); // Cap at 95 to never exceed true emergencies

                if ($newScore > $ticket->priority_score) {
                    $ticket->update(['priority_score' => $newScore]);
                    $adjusted++;
                }
            }
        }

        return $adjusted;
    }

    /**
     * Get queue congestion summary for a given RHU.
     *
     * @return array{
     *   total_waiting: int,
     *   avg_wait_minutes: float,
     *   congestion_level: string,
     *   by_priority: array<string, int>
     * }
     */
    public function getQueueCongestion(int $rhuId): array
    {
        $waiting = QueueTicket::where('rhu_id', $rhuId)
            ->where('status', 'waiting')
            ->whereDate('issued_at', today())
            ->get();

        $totalWaiting   = $waiting->count();
        $avgWaitMinutes = $totalWaiting > 0
            ? round($waiting->avg(fn($t) => now()->diffInMinutes($t->issued_at)), 1)
            : 0;

        return [
            'total_waiting'     => $totalWaiting,
            'avg_wait_minutes'  => $avgWaitMinutes,
            'congestion_level'  => $this->classifyCongestion($totalWaiting),
            'by_priority'       => [
                'critical' => $waiting->where('priority_category', 'emergency')->count(),
                'high'     => $waiting->filter(fn($t) => $t->priority_score >= 60 && $t->priority_category !== 'emergency')->count(),
                'moderate' => $waiting->filter(fn($t) => $t->priority_score >= 35 && $t->priority_score < 60)->count(),
                'low'      => $waiting->filter(fn($t) => $t->priority_score < 35)->count(),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Load active priority rules from DB, keyed by rule_key.
     */
    private function loadActiveRules(): array
    {
        return QueuePriorityRule::where('is_active', true)
            ->pluck('score_weight', 'rule_key')
            ->toArray();
    }

    /**
     * Check for chronic illness indicators in past consultation diagnoses.
     */
    private function hasChronicIllnessHistory(int $userId): bool
    {
        $chronicKeywords = [
            'hypertension', 'diabetes', 'asthma', 'copd',
            'heart disease', 'kidney disease', 'tuberculosis',
            'cancer', 'epilepsy', 'arthritis',
        ];

        return Consultation::where('user_id', $userId)
            ->where(function ($query) use ($chronicKeywords) {
                foreach ($chronicKeywords as $keyword) {
                    $query->orWhere('diagnosis', 'ILIKE', "%{$keyword}%");
                }
            })
            ->exists();
    }

    /**
     * Compute barangay risk weight from today's heatmap data.
     */
    private function computeBarangayRiskWeight(?int $barangayId): int
    {
        if (!$barangayId) {
            return 0;
        }

        $latestRisk = DB::table('barangay_heatmaps')
            ->where('barangay_id', $barangayId)
            ->where('log_date', today())
            ->orderByDesc('heatmap_intensity')
            ->value('risk_level');

        if (!$latestRisk) {
            return 0;
        }

        return match ($latestRisk) {
            'critical' => self::BARANGAY_RISK_CRITICAL,
            'high'     => self::BARANGAY_RISK_HIGH,
            'moderate' => self::BARANGAY_RISK_MODERATE,
            default    => 0,
        };
    }

    /**
     * Classify priority level from score.
     *
     * Priority Levels (as defined in thesis):
     *   Critical  — score >= 80 OR explicit emergency
     *   High      — score >= 60
     *   Moderate  — score >= 35
     *   Low       — score <  35
     */
    private function classifyPriority(int $score, bool $isEmergency): string
    {
        if ($isEmergency || $score >= 80) return 'Critical';
        if ($score >= 60) return 'High';
        if ($score >= 35) return 'Moderate';
        return 'Low';
    }

    /**
     * Determine the queue type based on patient context.
     */
    private function resolveQueueType(array $context, ?int $age): string
    {
        if (!empty($context['is_emergency'])) return 'emergency';
        if (!empty($context['is_pregnant']))  return 'pregnant';
        if ($age !== null && $age >= 60)       return 'senior';
        if (!empty($context['is_pwd']))        return 'pwd';
        if (!empty($context['appointment_id'])) return 'pre_registered';
        return 'walk_in';
    }

    /**
     * Classify queue congestion level based on active ticket count.
     */
    private function classifyCongestion(int $waitingCount): string
    {
        if ($waitingCount >= 50) return 'critical';
        if ($waitingCount >= 30) return 'high';
        if ($waitingCount >= 15) return 'moderate';
        return 'low';
    }
}
