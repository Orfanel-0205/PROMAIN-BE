<?php
// app/Services/Ai/CmsDraftParser.php

namespace App\Services\Ai;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Turns the staff assistant's CMS content draft into a structured payload the
 * web admin can load straight into the Event Creation form.
 *
 * The staff system prompt pins the draft to a fixed set of labelled lines
 * ("**Title:** ...", "**SMS Summary:** ..."), so this parser only has to read
 * those labels back. It deliberately stays permissive: anything it cannot
 * recognise is simply left out, and a reply that is not a draft at all returns
 * null so the chat renders as ordinary text.
 *
 * Free-text values (audiences, services) are returned as-is. The frontend owns
 * the canonical option lists (targetAudiences.ts / rhuServices.ts) and matches
 * against them there, so there is exactly one source of truth for those.
 */
class CmsDraftParser
{
    /**
     * Label -> form field. Order matters: longer/more specific labels first so
     * "Start Date & Time" is not swallowed by a looser "Date" match.
     */
    private const LABELS = [
        'post type' => 'event_type',
        'category' => 'category',
        'title' => 'title',
        'description' => 'description',
        'start date & time' => 'event_date',
        'start date and time' => 'event_date',
        'start date/time' => 'event_date',
        'start date' => 'event_date',
        'end date & time' => 'ends_at',
        'end date and time' => 'ends_at',
        'end date/time' => 'ends_at',
        'end date' => 'ends_at',
        'location / venue' => 'location',
        'location/venue' => 'location',
        'location' => 'location',
        'venue' => 'location',
        'target audience' => 'target_audience',
        'barangay target' => 'barangay_target',
        'maximum slots' => 'max_slots',
        'max slots' => 'max_slots',
        'rhu service offered' => 'services',
        'rhu service' => 'services',
        'priority' => 'priority',
        'visibility' => 'visibility',
        'tags' => 'tags',
        'sms summary' => 'sms_summary',
    ];

    /** A reply must carry at least these to count as a usable draft. */
    private const REQUIRED = ['title', 'description'];

    public function parse(?string $reply): ?array
    {
        if (!is_string($reply) || trim($reply) === '') {
            return null;
        }

        $collected = $this->collectLabelledValues($reply);

        foreach (self::REQUIRED as $field) {
            if (trim((string) ($collected[$field] ?? '')) === '') {
                return null;
            }
        }

        $draft = [
            'event_type' => $this->normalizeEventType($collected['event_type'] ?? null),
            'category' => $this->cleanScalar($collected['category'] ?? null),
            'title' => $this->cleanScalar($collected['title'] ?? null),
            'description' => $this->cleanBlock($collected['description'] ?? null),
            'event_date' => $this->normalizeDateTime($collected['event_date'] ?? null),
            'ends_at' => $this->normalizeDateTime($collected['ends_at'] ?? null),
            'location' => $this->cleanScalar($collected['location'] ?? null),
            'target_audience' => $this->normalizeList($collected['target_audience'] ?? null),
            'barangay_target' => $this->normalizeBarangayTarget($collected['barangay_target'] ?? null),
            'max_slots' => $this->normalizeSlots($collected['max_slots'] ?? null),
            'services' => $this->normalizeList($collected['services'] ?? null),
            'priority' => $this->normalizePriority($collected['priority'] ?? null),
            'visibility' => $this->normalizeVisibility($collected['visibility'] ?? null),
            'tags' => $this->normalizeList($collected['tags'] ?? null),
            'sms_summary' => $this->normalizeSmsSummary($collected['sms_summary'] ?? null),
        ];

        // An end date earlier than the start would only trip the form's own
        // validation, so drop it rather than hand over something invalid.
        if ($draft['event_date'] && $draft['ends_at'] && $draft['ends_at'] < $draft['event_date']) {
            $draft['ends_at'] = '';
        }

        return $draft;
    }

    /**
     * Walk the reply line by line. A recognised "Label: value" starts a field;
     * subsequent unlabelled lines belong to it (Description is often several
     * lines / bullets long).
     */
    private function collectLabelledValues(string $reply): array
    {
        $collected = [];
        $current = null;

        foreach (preg_split('/\r\n|\r|\n/', $reply) ?: [] as $rawLine) {
            $line = $this->stripDecoration($rawLine);

            if ($line === '') {
                continue;
            }

            [$field, $value] = $this->matchLabel($line);

            if ($field !== null) {
                // First occurrence wins, so a later click-path mention of e.g.
                // "Title" cannot overwrite the real drafted value.
                if (!array_key_exists($field, $collected)) {
                    $collected[$field] = $value;
                    $current = $field;
                } else {
                    $current = null;
                }

                continue;
            }

            // Only Description legitimately spans several lines. Letting every
            // field continue made "SMS Summary" swallow the "Banner image:" line
            // that follows it in the draft.
            if ($current !== 'description') {
                continue;
            }

            if ($collected[$current] !== '') {
                $collected[$current] .= "\n" . $line;
            } else {
                $collected[$current] = $line;
            }
        }

        return $collected;
    }

    /** Remove markdown bold/italics, bullets and numbered-list prefixes. */
    private function stripDecoration(string $line): string
    {
        $line = str_replace(['**', '__'], '', $line);
        $line = preg_replace('/^\s*(?:[-*\x{2022}\x{00B7}]|\d+[.)])\s+/u', '', $line) ?? $line;

        return trim($line);
    }

    /** @return array{0: ?string, 1: string} */
    private function matchLabel(string $line): array
    {
        $position = mb_strpos($line, ':');

        if ($position === false || $position === 0) {
            return [null, ''];
        }

        $label = mb_strtolower(trim(mb_substr($line, 0, $position)));
        $label = trim($label, " \t*_#-\u{2014}\u{2013}");

        if (!array_key_exists($label, self::LABELS)) {
            return [null, ''];
        }

        return [self::LABELS[$label], trim(mb_substr($line, $position + 1))];
    }

    private function cleanScalar(?string $value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function cleanBlock(?string $value): string
    {
        $value = trim((string) $value);
        // Collapse 3+ blank lines but keep paragraph/bullet structure.
        $value = preg_replace('/\n{3,}/', "\n\n", $value) ?? $value;

        return trim($value);
    }

    /** Drop a trailing "(please confirm ...)" style note before parsing. */
    private function stripParenthetical(string $value): string
    {
        return trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $value) ?? $value);
    }

    private function normalizeDateTime(?string $value): string
    {
        $value = $this->stripParenthetical($this->cleanScalar($value));

        if ($value === '' || Str::contains(mb_strtolower($value), ['n/a', 'none', 'wala', 'tbd', 'to be'])) {
            return '';
        }

        try {
            // datetime-local wants exactly YYYY-MM-DDTHH:MM.
            return Carbon::parse($value)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private function normalizeEventType(?string $value): string
    {
        $value = mb_strtolower($this->cleanScalar($value));

        return match (true) {
            str_contains($value, 'announcement') => 'announcement',
            str_contains($value, 'program') => 'program',
            str_contains($value, 'event') => 'event',
            default => 'event',
        };
    }

    private function normalizePriority(?string $value): string
    {
        $value = mb_strtolower($this->cleanScalar($value));

        return match (true) {
            str_contains($value, 'urgent') => 'urgent',
            str_contains($value, 'high') => 'high',
            default => 'normal',
        };
    }

    private function normalizeVisibility(?string $value): string
    {
        $value = mb_strtolower($this->cleanScalar($value));

        if (preg_match('/rhu\s*1/', $value) === 1) {
            return 'rhu1';
        }

        if (preg_match('/rhu\s*2/', $value) === 1) {
            return 'rhu2';
        }

        return 'public';
    }

    private function normalizeSlots(?string $value): string
    {
        $value = mb_strtolower($this->cleanScalar($value));

        if ($value === '' || Str::contains($value, ['no limit', 'unlimited', 'walang limit', 'open to all', 'n/a'])) {
            return '';
        }

        if (preg_match('/\d[\d,]*/', $value, $matches) === 1) {
            $slots = (int) str_replace(',', '', $matches[0]);

            return $slots >= 1 ? (string) $slots : '';
        }

        return '';
    }

    private function normalizeBarangayTarget(?string $value): string
    {
        $value = $this->cleanScalar($value);

        $lower = mb_strtolower($value);

        // Deliberately anchored: a bare Str::contains(..., 'all') would also
        // match barangay names that merely contain those letters.
        if (
            $lower === ''
            || $lower === 'all'
            || Str::startsWith($lower, ['all barangay', 'all residents', 'lahat'])
        ) {
            return 'all';
        }

        return implode(', ', $this->normalizeList($value));
    }

    private function normalizeSmsSummary(?string $value): string
    {
        $value = $this->cleanScalar($value);
        // The prompt asks the model to append "(158 characters)" - not part of
        // the SMS. Not anchored to the end: the note is sometimes followed by
        // trailing prose.
        $value = preg_replace('/\s*\(\s*\d+\s*(?:characters?|chars?)\s*\)\s*/iu', ' ', $value) ?? $value;
        $value = $this->cleanScalar($value);
        $value = trim(trim($value), '"');

        return mb_substr(trim($value), 0, 160);
    }

    /** @return array<int, string> */
    private function normalizeList(?string $value): array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        // Split on commas/semicolons/newlines at PAREN DEPTH 0 only, and never
        // on '/'. Both matter: canonical options contain '/' ("Adolescents /
        // Youth"), and a grouped service like "Nutrition (Growth Monitoring,
        // Nutrition Counseling)" must not be torn apart at its inner comma.
        $parts = $this->splitTopLevel($value);

        $clean = [];

        foreach ($parts as $part) {
            $part = trim($this->stripDecoration($part));
            $part = trim($part, " .\u{2022}");

            if ($part === '' || mb_strlen($part) > 120) {
                continue;
            }

            foreach ($this->expandGrouped($part) as $candidate) {
                if ($candidate !== '' && !in_array($candidate, $clean, true)) {
                    $clean[] = $candidate;
                }
            }
        }

        return $clean;
    }

    /**
     * Split on , ; and newlines, but only outside parentheses.
     *
     * @return array<int, string>
     */
    private function splitTopLevel(string $value): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;

        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($depth === 0 && ($char === ',' || $char === ';' || $char === "\n")) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
    }

    /**
     * "Nutrition (Growth Monitoring, Nutrition Counseling)" -> the whole string
     * PLUS "Nutrition", "Growth Monitoring", "Nutrition Counseling".
     *
     * The frontend intersects these candidates with the canonical catalogs, so
     * offering the group label and its members is safe: whichever form is a real
     * option wins, and entries like "Infants (0-11 months)" - which ARE canonical
     * in full - still match on the untouched original.
     *
     * @return array<int, string>
     */
    private function expandGrouped(string $part): array
    {
        $candidates = [$part];

        if (preg_match('/^(.*?)\s*\((.+)\)$/u', $part, $matches) !== 1) {
            return $candidates;
        }

        $outer = trim($matches[1]);
        $inner = trim($matches[2]);

        // Only expand when the parenthetical really is a list; this leaves
        // qualifiers like "Infants (0-11 months)" alone.
        if (!str_contains($inner, ',')) {
            return $candidates;
        }

        if ($outer !== '') {
            $candidates[] = $outer;
        }

        foreach ($this->splitTopLevel($inner) as $item) {
            $item = trim($item);

            if ($item !== '') {
                $candidates[] = $item;
            }
        }

        return $candidates;
    }
}
