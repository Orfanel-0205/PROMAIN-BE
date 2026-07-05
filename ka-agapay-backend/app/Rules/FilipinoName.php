<?php
// app/Rules/FilipinoName.php
//
// Reusable validation rule that rejects obviously fake / abusive names while
// accepting realistic Filipino personal names (first name OR last name — apply
// it per field, not to a full "Juan Dela Cruz" string, though multi-word values
// like "Anne Marie" and "Dela Cruz" are fully supported).
//
// ACCEPTS  : Juan, Maria, Anne Marie, Dela Cruz, Santos, Peña, Ng, Sy,
//            Santos-Reyes, Ma. Cristina, D'Souza
// REJECTS  : this-is-a-test-by-panel-members, http://x.com, www.test.com,
//            "SELECT * FROM users", testtesttest, aaaaaaa, asdfasdf, 12345,
//            !!!, a, x
//
// Implemented with the classic Illuminate\Contracts\Validation\Rule interface
// (fully supported on Laravel 10) so it drops into any FormRequest rules array.

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class FilipinoName implements Rule
{
    private string $failReason = 'Please enter a valid name.';

    /** Words that are never real names (checked as whole tokens and substrings). */
    private const BANNED_SUBSTRINGS = [
        'test', 'asdf', 'qwer', 'zxcv', 'lorem', 'ipsum', 'dummy',
        'sample', 'admin', 'null', 'undefined', 'nan', 'xxxx',
    ];

    /** SQL / script fragments that should never appear in a name. */
    private const INJECTION_SUBSTRINGS = [
        'select ', 'insert ', 'update ', 'delete ', 'drop ', 'union ',
        '--', ';--', '/*', '*/', '<script', 'onerror', 'onload', '0x',
    ];

    public function passes($attribute, $value): bool
    {
        $raw = is_string($value) ? trim($value) : '';

        // Collapse any run of internal whitespace to a single space.
        $name = preg_replace('/\s+/u', ' ', $raw);

        if ($name === '') {
            return $this->fail('Name is required.');
        }

        // 1) Length sanity. Real names are 2–60 chars.
        $length = mb_strlen($name);
        if ($length < 2) {
            return $this->fail('Name is too short.');
        }
        if ($length > 60) {
            return $this->fail('Name is too long.');
        }

        // 2) URL / email-like content (www.x, http, .com, @) — catches names
        //    that survive the character whitelist below (e.g. "www.test.com").
        $lower = mb_strtolower($name);
        if (preg_match('/(https?:|www\.|@|\.(com|net|org|ph|io|xyz|info)\b)/u', $lower)) {
            return $this->fail('Name cannot contain a website or email address.');
        }

        // 3) SQL / script injection fragments.
        foreach (self::INJECTION_SUBSTRINGS as $needle) {
            if (str_contains($lower, $needle)) {
                return $this->fail('Name contains invalid characters.');
            }
        }

        // 4) Character whitelist: unicode letters (incl. ñ, é…), spaces, and the
        //    handful of punctuation marks real names use: hyphen, apostrophe,
        //    period. This alone rejects digits, symbols, and most gibberish.
        if (!preg_match("/^[\p{L} .'\x{2019}-]+$/u", $name)) {
            return $this->fail('Name can only contain letters, spaces, hyphens, apostrophes, and periods.');
        }

        // Must actually contain letters (not just ". . -").
        if (preg_match_all('/\p{L}/u', $name) < 2) {
            return $this->fail('Please enter a valid name.');
        }

        // 5) Hyphen abuse: at most one hyphen (e.g. "Santos-Reyes"); it must sit
        //    between letters. Kills "this-is-a-test-by-panel-members".
        if (substr_count($name, '-') > 1) {
            return $this->fail('Name has too many hyphens.');
        }
        if (preg_match('/(^-|-$|--|\s-|-\s)/u', $name)) {
            return $this->fail('Hyphen must be placed between letters.');
        }

        // ── Heuristic checks run on an ASCII-folded, letters-only version ──────
        $folded = $this->foldLettersOnly($name); // e.g. "annemarie", "delacruz"

        // 6) Banned keyword tokens (test/asdf/lorem…) as substrings.
        foreach (self::BANNED_SUBSTRINGS as $needle) {
            if (str_contains($folded, $needle)) {
                return $this->fail('Please enter your real name.');
            }
        }

        // 7) Same character repeated 4+ times: "aaaa", "aaaaaaa".
        if (preg_match('/(.)\1{3,}/u', $folded)) {
            return $this->fail('Please enter your real name.');
        }

        // 8) Whole-value is a short block repeated: "testtest", "asdfasdf",
        //    "abcabcabc". A real name is not a 1–4 char unit tiled N times.
        if ($this->isRepeatedBlock($folded)) {
            return $this->fail('Please enter your real name.');
        }

        // 9) Keyboard mashing: 5+ consecutive consonants (e.g. "sdfghjkl").
        //    Threshold is generous so genuine clusters like "Ng"/"Sy" and long
        //    Ilocano/Pangasinan names pass untouched.
        if (preg_match('/[bcdfghjklmnpqrstvwxyz]{5,}/', $folded)) {
            return $this->fail('Please enter your real name.');
        }

        return true;
    }

    public function message(): string
    {
        return $this->failReason;
    }

    private function fail(string $reason): bool
    {
        $this->failReason = $reason;
        return false;
    }

    /** Lowercase, transliterate accents to ASCII, strip everything but a–z. */
    private function foldLettersOnly(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($ascii === false) {
            $ascii = $name;
        }

        return preg_replace('/[^a-z]/', '', strtolower($ascii)) ?? '';
    }

    /** True when $s (len >= 4) is a 1–4 char block tiled 2+ times. */
    private function isRepeatedBlock(string $s): bool
    {
        $len = strlen($s);
        if ($len < 4) {
            return false;
        }

        for ($unit = 1; $unit <= 4 && $unit <= intdiv($len, 2); $unit++) {
            if ($len % $unit !== 0) {
                continue;
            }
            if (str_repeat(substr($s, 0, $unit), intdiv($len, $unit)) === $s) {
                return true;
            }
        }

        return false;
    }
}
