<?php
// app/Support/ComplaintGrouping.php

namespace App\Support;

/**
 * Turning what a patient said into something that can be counted.
 *
 * Outbreak detection asks "how many people in this barangay reported the same
 * thing this week". Chief complaints are free text typed by whoever was at the
 * desk, in whichever language the conversation happened in, so the raw strings
 * do not group: this system already holds "Ubo" eleven times, "Lagnat" nine,
 * "Headache" twice and "Sakit ng ulo" twice. Counted literally, the last two
 * are two separate complaints of two cases each. They are the same complaint
 * with four.
 *
 * So each complaint is mapped to a canonical condition before counting.
 *
 * WHAT THIS IS NOT
 * ----------------
 * This is not triage and it is not a diagnosis. It groups words for the
 * purpose of noticing that several people in one barangay are describing the
 * same thing, which is a signal to go and look -- nothing more. A complaint it
 * does not recognise keeps its own wording and is still counted; it simply
 * only groups with an identical entry.
 */
final class ComplaintGrouping
{
    /**
     * Canonical condition => the words that mean it.
     *
     * Tagalog first in each list, because that is what gets typed most often
     * at the desk. Matching is on whole words against a lowercased,
     * punctuation-stripped complaint, so "ubo" does not match "ubos".
     *
     * @var array<string, array<int, string>>
     */
    private const GROUPS = [
        'Fever' => ['lagnat', 'nilalagnat', 'fever', 'febrile', 'init ng katawan'],
        'Cough' => ['ubo', 'inuubo', 'cough', 'coughing'],
        'Colds' => ['sipon', 'may sipon', 'colds', 'cold', 'runny nose', 'baradong ilong'],
        'Influenza-like illness' => ['trangkaso', 'flu', 'influenza', 'ubo at lagnat', 'lagnat at ubo'],
        'Headache' => ['sakit ng ulo', 'masakit ang ulo', 'headache', 'migraine', 'migraina'],
        'Diarrhoea' => ['pagtatae', 'nagtatae', 'diarrhea', 'diarrhoea', 'loose bowel', 'lbm'],
        'Vomiting' => ['nagsusuka', 'pagsusuka', 'vomiting', 'vomit'],
        'Abdominal pain' => ['sakit ng tiyan', 'masakit ang tiyan', 'stomach ache', 'abdominal pain', 'tummy pain'],
        'Skin rash' => ['pantal', 'rash', 'skin rash', 'butlig', 'makati ang balat'],
        'Sore throat' => ['masakit ang lalamunan', 'sore throat', 'namamagang lalamunan'],
        'Difficulty breathing' => ['hirap huminga', 'difficulty breathing', 'shortness of breath', 'hika', 'asthma'],
        'Dengue' => ['dengue', 'dengue fever', 'hemorrhagic'],
        'Measles' => ['tigdas', 'measles', 'rubella'],
        'Cholera' => ['cholera', 'kolera'],
        'Typhoid' => ['typhoid', 'tipus', 'typhoid fever'],
        'Chickenpox' => ['bulutong', 'chickenpox', 'varicella'],
        'Tuberculosis' => ['tb', 'tuberculosis', 'ptb'],
        'Animal bite' => ['kagat ng aso', 'dog bite', 'animal bite', 'kagat ng pusa', 'cat bite'],
        'Hypertension' => ['high blood', 'hypertension', 'altapresyon'],
        'Wound' => ['sugat', 'wound', 'laceration', 'hiwa'],
    ];

    /**
     * Conditions where waiting for a third case is waiting too long.
     *
     * These are notifiable under the Philippine Integrated Disease
     * Surveillance and Response system: two linked cases in one barangay is
     * already a cluster worth walking out to see. The cost of being wrong is a
     * wasted visit; the cost of being late is an outbreak with a head start.
     */
    private const NOTIFIABLE = [
        'Dengue',
        'Measles',
        'Cholera',
        'Typhoid',
        'Chickenpox',
    ];

    /**
     * The canonical condition for a free-text complaint.
     *
     * Returns the complaint's own trimmed wording when nothing matches, so an
     * unrecognised complaint still counts -- it just only groups with an
     * identical one.
     */
    public static function normalize(?string $complaint): string
    {
        $text = self::simplify($complaint);

        if ($text === '') {
            return 'Unspecified';
        }

        /*
         * Longest phrase wins.
         *
         * Checking groups in declaration order let a generic term beat a
         * specific one: "Dengue fever" matched 'fever' and was filed as
         * Fever, losing the lower threshold that makes Dengue worth
         * noticing at two cases. "Lagnat at ubo" matched 'lagnat' rather
         * than the influenza-like phrase it actually is.
         *
         * Ordering by term length instead of by hand also means a new term
         * can be added anywhere in the table without thinking about where
         * it sits relative to the others.
         */
        foreach (self::termsByLengthDesc() as [$term, $condition]) {
            if (self::containsPhrase($text, $term)) {
                return $condition;
            }
        }

        // Unrecognised: keep the original wording, capped so a paragraph typed
        // into the complaint box does not become a condition name.
        $original = trim((string) $complaint);

        return mb_strimwidth($original, 0, 80, '…');
    }

    /** Whether two linked cases of this condition already warrant a look. */
    public static function isNotifiable(string $condition): bool
    {
        return in_array($condition, self::NOTIFIABLE, true);
    }

    /** The case count at which this condition becomes an alert. */
    public static function threshold(string $condition, int $default = 3): int
    {
        return self::isNotifiable($condition) ? 2 : $default;
    }

    /**
     * Every term paired with its condition, longest first.
     *
     * Built once per request. The table is a couple of hundred entries, so
     * sorting it is cheaper than the alternative of maintaining the order by
     * hand and getting it wrong.
     *
     * @return array<int, array{0:string, 1:string}>
     */
    private static function termsByLengthDesc(): array
    {
        static $sorted = null;

        if ($sorted !== null) {
            return $sorted;
        }

        $pairs = [];

        foreach (self::GROUPS as $condition => $terms) {
            foreach ($terms as $term) {
                $pairs[] = [$term, $condition];
            }
        }

        usort($pairs, fn ($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        return $sorted = $pairs;
    }

    /**
     * Lowercase, strip punctuation, collapse whitespace.
     *
     * Padded with spaces so whole-word matching can use plain string search
     * rather than a regular expression per term -- there are enough terms that
     * the difference is worth having.
     */
    private static function simplify(?string $complaint): string
    {
        $text = mb_strtolower(trim((string) $complaint));

        if ($text === '') {
            return '';
        }

        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return ' ' . trim($text) . ' ';
    }

    /** Whole-word (or whole-phrase) containment within a padded string. */
    private static function containsPhrase(string $paddedText, string $term): bool
    {
        return str_contains($paddedText, ' ' . $term . ' ');
    }
}
