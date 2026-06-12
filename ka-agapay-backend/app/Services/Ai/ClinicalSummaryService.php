<?php
// app/Services/Ai/ClinicalSummaryService.php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class ClinicalSummaryService
{
    /**
     * Generates a SOAP-style clinical summary.
     *
     * This works even without Gemini/OpenAI.
     * It uses rule-based extraction so the feature will not fail during demo.
     */
    public function summarize(array $input): array
    {
        $transcript = $this->clean($input['transcript'] ?? '');
        $chiefComplaint = $this->clean($input['chief_complaint'] ?? '');
        $existingSubjective = $this->clean($input['subjective'] ?? '');
        $existingObjective = $this->clean($input['objective'] ?? '');
        $existingAssessment = $this->clean($input['assessment'] ?? '');
        $existingPlan = $this->clean($input['plan'] ?? '');
        $existingDiagnosis = $this->clean($input['diagnosis'] ?? '');
        $existingTreatment = $this->clean($input['treatment'] ?? '');
        $existingNotes = $this->clean($input['notes'] ?? '');

        $source = $this->clean(implode("\n", array_filter([
            $chiefComplaint ? "Chief complaint: {$chiefComplaint}" : null,
            $existingSubjective ? "Subjective: {$existingSubjective}" : null,
            $existingObjective ? "Objective: {$existingObjective}" : null,
            $existingAssessment ? "Assessment: {$existingAssessment}" : null,
            $existingPlan ? "Plan: {$existingPlan}" : null,
            $existingDiagnosis ? "Diagnosis: {$existingDiagnosis}" : null,
            $existingTreatment ? "Treatment: {$existingTreatment}" : null,
            $existingNotes ? "Notes: {$existingNotes}" : null,
            $transcript ? "Transcript: {$transcript}" : null,
        ])));

        if ($source === '') {
            return $this->emptySummary();
        }

        $subjective = $existingSubjective ?: $this->extractSubjective($source, $chiefComplaint);
        $objective = $existingObjective ?: $this->extractObjective($source);
        $assessment = $existingAssessment ?: $existingDiagnosis ?: $this->extractAssessment($source);
        $plan = $existingPlan ?: $existingTreatment ?: $this->extractPlan($source);

        $diagnosis = $existingDiagnosis ?: $assessment;
        $treatment = $existingTreatment ?: $plan;

        $summary = $this->buildPlainSummary([
            'chief_complaint' => $chiefComplaint,
            'subjective' => $subjective,
            'objective' => $objective,
            'assessment' => $assessment,
            'plan' => $plan,
        ]);

        return [
            'summary' => $summary,
            'chief_complaint' => $chiefComplaint ?: $this->detectChiefComplaint($source),
            'subjective' => $subjective,
            'objective' => $objective,
            'assessment' => $assessment,
            'plan' => $plan,
            'diagnosis' => $diagnosis,
            'treatment' => $treatment,
            'red_flags' => $this->detectRedFlags($source),
            'follow_up' => $this->suggestFollowUp($source),
            'confidence' => $this->confidenceScore($source),
            'source' => 'rule_based_summary',
        ];
    }

    private function emptySummary(): array
    {
        return [
            'summary' => 'No transcript or consultation notes were provided for summarization.',
            'chief_complaint' => 'Not stated',
            'subjective' => 'Not stated',
            'objective' => 'Not stated',
            'assessment' => 'Not stated',
            'plan' => 'Not stated',
            'diagnosis' => 'Not stated',
            'treatment' => 'Not stated',
            'red_flags' => [],
            'follow_up' => 'Add consultation notes or transcript, then generate the summary again.',
            'confidence' => 0,
            'source' => 'rule_based_summary',
        ];
    }

    private function clean(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function extractSubjective(string $source, string $chiefComplaint = ''): string
    {
        $symptoms = [];

        $keywords = [
            'fever' => 'fever',
            'lagnat' => 'fever',
            'ubo' => 'cough',
            'cough' => 'cough',
            'sipon' => 'colds',
            'cold' => 'colds',
            'sakit ng ulo' => 'headache',
            'headache' => 'headache',
            'sakit ng tiyan' => 'abdominal pain',
            'stomach' => 'abdominal pain',
            'diarrhea' => 'diarrhea',
            'pagtatae' => 'diarrhea',
            'vomit' => 'vomiting',
            'suka' => 'vomiting',
            'dizzy' => 'dizziness',
            'nahihilo' => 'dizziness',
            'rash' => 'rash',
            'sugat' => 'wound',
            'pain' => 'pain',
            'masakit' => 'pain',
        ];

        $lower = strtolower($source);

        foreach ($keywords as $keyword => $label) {
            if (str_contains($lower, $keyword)) {
                $symptoms[] = $label;
            }
        }

        $symptoms = array_values(array_unique($symptoms));

        if ($chiefComplaint !== '') {
            return 'Patient reports ' . $chiefComplaint . '.'
                . (!empty($symptoms) ? ' Associated symptoms include ' . implode(', ', $symptoms) . '.' : '');
        }

        if (!empty($symptoms)) {
            return 'Patient reports symptoms including ' . implode(', ', $symptoms) . '.';
        }

        return Str::limit($source, 250, '...');
    }

    private function extractObjective(string $source): string
    {
        $objective = [];

        if (preg_match('/\b(?:temp|temperature|t)\s*[:\-]?\s*(\d{2}(?:\.\d+)?)\s*°?\s*c?\b/i', $source, $m)) {
            $objective[] = 'Temperature: ' . $m[1] . '°C';
        }

        if (preg_match('/\b(?:bp|blood pressure)\s*[:\-]?\s*(\d{2,3}\/\d{2,3})\b/i', $source, $m)) {
            $objective[] = 'BP: ' . $m[1];
        }

        if (preg_match('/\b(?:hr|heart rate|pulse)\s*[:\-]?\s*(\d{2,3})\b/i', $source, $m)) {
            $objective[] = 'HR: ' . $m[1] . ' bpm';
        }

        if (preg_match('/\b(?:spo2|oxygen|o2)\s*[:\-]?\s*(\d{2,3})%?\b/i', $source, $m)) {
            $objective[] = 'SpO2: ' . $m[1] . '%';
        }

        if (preg_match('/\b(?:weight|wt|timbang)\s*[:\-]?\s*(\d{1,3}(?:\.\d+)?)\s*(?:kg)?\b/i', $source, $m)) {
            $objective[] = 'Weight: ' . $m[1] . ' kg';
        }

        if (!empty($objective)) {
            return implode('; ', $objective) . '.';
        }

        return 'No objective vital signs or physical findings were clearly stated.';
    }

    private function extractAssessment(string $source): string
    {
        $lower = strtolower($source);

        if (str_contains($lower, 'fever') || str_contains($lower, 'lagnat')) {
            return 'Fever; assess for common infectious causes and monitor for warning signs.';
        }

        if (str_contains($lower, 'cough') || str_contains($lower, 'ubo')) {
            return 'Cough/upper respiratory symptoms; consider respiratory infection and monitor for breathing difficulty.';
        }

        if (str_contains($lower, 'diarrhea') || str_contains($lower, 'pagtatae')) {
            return 'Diarrhea; assess hydration status and possible gastrointestinal infection.';
        }

        if (str_contains($lower, 'headache') || str_contains($lower, 'sakit ng ulo')) {
            return 'Headache; assess severity, duration, triggers, fever, and neurologic warning signs.';
        }

        if (str_contains($lower, 'wound') || str_contains($lower, 'sugat')) {
            return 'Wound concern; assess wound depth, bleeding, infection signs, and tetanus status.';
        }

        return 'Assessment requires clinician review based on history, examination, and available findings.';
    }

    private function extractPlan(string $source): string
    {
        $lower = strtolower($source);
        $plans = [];

        if (str_contains($lower, 'fever') || str_contains($lower, 'lagnat')) {
            $plans[] = 'Encourage fluids and rest; monitor temperature.';
        }

        if (str_contains($lower, 'cough') || str_contains($lower, 'ubo')) {
            $plans[] = 'Advise hydration and observe for difficulty breathing or worsening cough.';
        }

        if (str_contains($lower, 'diarrhea') || str_contains($lower, 'pagtatae')) {
            $plans[] = 'Advise oral rehydration and monitor for dehydration.';
        }

        if (str_contains($lower, 'wound') || str_contains($lower, 'sugat')) {
            $plans[] = 'Clean wound and assess need for dressing, tetanus update, or referral.';
        }

        if (empty($plans)) {
            $plans[] = 'Continue clinical evaluation and provide management according to RHU protocol.';
        }

        $redFlags = $this->detectRedFlags($source);

        if (!empty($redFlags)) {
            $plans[] = 'Escalate urgently due to red flags: ' . implode(', ', $redFlags) . '.';
        } else {
            $plans[] = 'Advise follow-up if symptoms persist or worsen.';
        }

        return implode(' ', $plans);
    }

    private function detectChiefComplaint(string $source): string
    {
        $lower = strtolower($source);

        $map = [
            'fever' => ['fever', 'lagnat'],
            'cough' => ['cough', 'ubo'],
            'headache' => ['headache', 'sakit ng ulo'],
            'abdominal pain' => ['abdominal pain', 'sakit ng tiyan', 'stomach pain'],
            'diarrhea' => ['diarrhea', 'pagtatae'],
            'wound' => ['wound', 'sugat'],
            'dizziness' => ['dizzy', 'nahihilo', 'hilo'],
        ];

        foreach ($map as $label => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $label;
                }
            }
        }

        return 'Not clearly stated';
    }

    private function detectRedFlags(string $source): array
    {
        $lower = strtolower($source);

        $flags = [
            'chest pain' => ['chest pain', 'sakit dibdib', 'pananakit ng dibdib'],
            'difficulty breathing' => ['difficulty breathing', 'hirap huminga', 'shortness of breath'],
            'severe bleeding' => ['severe bleeding', 'malakas na dugo', 'dumudugo nang malakas'],
            'loss of consciousness' => ['loss of consciousness', 'nawalan ng malay', 'unconscious'],
            'seizure' => ['seizure', 'kombulsyon', 'atake'],
            'stroke signs' => ['stroke', 'facial droop', 'slurred speech', 'panghihina ng kalahating katawan'],
            'high fever' => ['40°', '40 c', 'very high fever', 'mataas na lagnat'],
        ];

        $found = [];

        foreach ($flags as $label => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $found[] = $label;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function suggestFollowUp(string $source): string
    {
        $redFlags = $this->detectRedFlags($source);

        if (!empty($redFlags)) {
            return 'Immediate referral or urgent clinician review is recommended.';
        }

        return 'Follow up at RHU if symptoms persist, worsen, or new warning signs appear.';
    }

    private function confidenceScore(string $source): int
    {
        $length = strlen($source);

        if ($length > 700) {
            return 85;
        }

        if ($length > 300) {
            return 75;
        }

        if ($length > 80) {
            return 60;
        }

        return 40;
    }

    private function buildPlainSummary(array $data): string
    {
        return trim(
            "Chief Complaint: " . ($data['chief_complaint'] ?: 'Not stated') . "\n" .
            "Subjective: " . ($data['subjective'] ?: 'Not stated') . "\n" .
            "Objective: " . ($data['objective'] ?: 'Not stated') . "\n" .
            "Assessment: " . ($data['assessment'] ?: 'Not stated') . "\n" .
            "Plan: " . ($data['plan'] ?: 'Not stated')
        );
    }
}