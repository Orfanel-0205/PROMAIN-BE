<?php
// app/Services/Ai/FormDraftParser.php

namespace App\Services\Ai;

use Carbon\Carbon;

/**
 * Reads a follow-up schedule out of the assistant's reply so the web admin can
 * load it into the form the staff member already has open.
 *
 * This is the same idea as CmsDraftParser, applied to the other place staff
 * type the same thing over and over. The staff prompt pins the draft to fixed
 * labelled lines ("**Follow-up Date:** ..."), so this only has to read the
 * labels back. Anything it cannot recognise is left out, and a reply that is
 * not a draft returns null so the chat renders as ordinary text.
 *
 * WHAT IT WILL NOT CARRY
 *   Only the administrative side of a follow-up: when, how urgent, whether to
 *   text the patient, and a short plain-language reason. Not a diagnosis, not
 *   a medicine, not a dose, and not care instructions for the patient. The
 *   model is told not to produce those; this refuses them anyway, because a
 *   rule the model could drift from is not a safeguard. The instructions field
 *   is left for the clinician to write, deliberately.
 */
class FormDraftParser
{
    /** Label -> form field. Longer labels first so a looser one cannot swallow them. */
    private const LABELS = [
        'follow-up start date' => 'follow_up_start_date',
        'follow up start date' => 'follow_up_start_date',
        'follow-up end date' => 'follow_up_end_date',
        'follow up end date' => 'follow_up_end_date',
        'follow-up date' => 'follow_up_date',
        'follow up date' => 'follow_up_date',
        'follow-up time' => 'follow_up_time',
        'follow up time' => 'follow_up_time',
        'follow-up type' => 'follow_up_type',
        'follow up type' => 'follow_up_type',
        'urgency' => 'urgency',
        'reason' => 'reason',
        'sms reminder' => 'sms_enabled',
        'sms' => 'sms_enabled',
    ];

    /** The form only offers these two shapes. */
    private const TYPES = ['single', 'range'];

    /** Matching the form's own options; anything else is dropped. */
    private const URGENCIES = ['routine', 'priority', 'urgent'];

    /**
     * @return array{form: string, fields: array<string, string>}|null
     */
    public function parse(?string $reply): ?array
    {
        if ($reply === null || trim($reply) === '') {
            return null;
        }

        // A draft announces itself. Without this, an ordinary sentence
        // mentioning a date would be treated as a schedule.
        if (!preg_match('/follow[\s-]?up (date|type|schedule)/i', $reply)) {
            return null;
        }

        $fields = [];

        foreach (preg_split('/\R/', $reply) as $line) {
            $clean = trim(str_replace('*', '', (string) $line));

            if ($clean === '' || !str_contains($clean, ':')) {
                continue;
            }

            [$rawLabel, $rawValue] = explode(':', $clean, 2);

            $label = strtolower(trim(preg_replace('/^\d+[\).]\s*/', '', $rawLabel)));
            $value = trim($rawValue);

            if ($value === '' || !isset(self::LABELS[$label])) {
                continue;
            }

            $field = self::LABELS[$label];

            // First mention wins, so a later summary line cannot overwrite the
            // draft with something vaguer.
            if (isset($fields[$field])) {
                continue;
            }

            $normalised = $this->normalise($field, $value);

            if ($normalised !== null) {
                $fields[$field] = $normalised;
            }
        }

        if (!isset($fields['follow_up_date']) && !isset($fields['follow_up_start_date'])) {
            return null; // A follow-up with no date is not a schedule.
        }

        return ['form' => 'follow_up', 'fields' => $fields];
    }

    private function normalise(string $field, string $value): ?string
    {
        return match ($field) {
            'follow_up_date', 'follow_up_start_date', 'follow_up_end_date' => $this->asDate($value),
            'follow_up_time' => $this->asTime($value),
            'follow_up_type' => in_array(strtolower($value), self::TYPES, true)
                ? strtolower($value)
                : (preg_match('/range|between|hanggang/i', $value) ? 'range' : 'single'),
            'urgency' => $this->asUrgency($value),
            'sms_enabled' => preg_match('/^(yes|on|enabled?|true|oo|opo)/i', $value) ? '1' : '0',
            // Kept short: this is a label on a reminder, not a clinical note.
            'reason' => mb_substr($value, 0, 120),
            default => null,
        };
    }

    private function asDate(string $value): ?string
    {
        // The model is asked to name the weekday so an accidental Sunday is
        // obvious to whoever reads the draft. That makes the line good for a
        // person and unparseable as a date, so the aside comes off first.
        $clean = trim(preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/', '', $value));
        $clean = trim($clean, " 	.,;");

        try {
            // It writes 2026-10-05, October 5 2026 or 5 Oct 2026 depending on
            // the language it answered in; all three parse.
            return Carbon::parse($clean)->format('Y-m-d');
        } catch (\Throwable) {
            // Last resort: a plain date sitting inside a longer sentence.
            if (preg_match('/\d{4}-\d{2}-\d{2}/', $clean, $match)) {
                try {
                    return Carbon::parse($match[0])->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            }

            return null;
        }
    }

    private function asTime(string $value): ?string
    {
        $clean = trim(preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/', '', $value));

        try {
            return Carbon::parse($clean)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function asUrgency(string $value): ?string
    {
        $lower = strtolower($value);

        foreach (self::URGENCIES as $urgency) {
            if (str_contains($lower, $urgency)) {
                return $urgency;
            }
        }

        // "emergency" and "ASAP" both mean the same thing to a nurse reading it.
        if (preg_match('/emergenc|asap|agad|immediate/i', $value)) {
            return 'urgent';
        }

        return null;
    }
}
