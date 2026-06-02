<?php

namespace App\Support;

/**
 * Canonical barangay name list for Ka-Agapay (Malasiqui, Pangasinan).
 *
 * ── IMPORTANT ──────────────────────────────────────────────────────────────
 * This list is kept ONLY for:
 *   • frontend constants reference (constants/barangay.ts must match this)
 *   • display / lookup utilities
 *   • the BarangaySeeder as a cross-check
 *
 * NEVER use this list for Laravel validation rules.
 * Always use:  Rule::exists('barangays', 'name')
 * That queries the DB at runtime and is always in sync.
 * A hardcoded in: rule here will drift from the DB and cause 422s.
 * ───────────────────────────────────────────────────────────────────────────
 *
 * Spellings are the authoritative source and must match BarangaySeeder.php
 * exactly — including capitalisation, spacing, and hyphens.
 *
 * Previous version had the following wrong spellings (now corrected):
 *   ❌ "Asin Este"        → ✅ "Asin East"
 *   ❌ "Asin Weste"       → ✅ "Asin West"
 *   ❌ "Bacundao Este"    → ✅ "Bacundao East"
 *   ❌ "Bacundao Weste"   → ✅ "Bacundao West"
 *   ❌ "Banawang"         → ✅ "Banaoang"
 *   ❌ "Butao"            → ✅ "Buto"
 *   ❌ "Ingalagala"       → ✅ "Ingala-Gala"
 *   ❌ "Lareg-lareg"      → ✅ "Lareg-Lareg"
 *   ❌ "Loqueb Este"      → ✅ "Lokeb Este"
 *   ❌ "Loqueb Norte"     → ✅ "Lokeb Norte"
 *   ❌ "Loqueb Sur"       → ✅ "Lokeb Sur"
 */
final class BarangayList
{
    public const LIST = [
        'Abonagan',
        'Agdao',
        'Alacan',
        'Aliaga',
        'Amacalan',
        'Anolid',
        'Apaya',
        'Asin East',
        'Asin West',
        'Bacundao East',
        'Bacundao West',
        'Bakitiw',
        'Balite',
        'Banaoang',
        'Barang',
        'Bawer',
        'Binalay',
        'Bobon',
        'Bolaoit',
        'Bongar',
        'Buto',
        'Cabatling',
        'Cabueldatan',
        'Calbueg',
        'Canan Norte',
        'Canan Sur',
        'Cawayan Bogtong',
        'Don Pedro',
        'Gatang',
        'Goliman',
        'Gomez',
        'Guilig',
        'Ican',
        'Ingala-Gala',
        'Lareg-Lareg',
        'Lasip',
        'Lepa',
        'Lokeb Este',
        'Lokeb Norte',
        'Lokeb Sur',
        'Lunec',
        'Mabulitec',
        'Malimpec',
        'Manggan-Dampay',
        'Nalsian Norte',
        'Nalsian Sur',
        'Nancapian',
        'Nansangaan',
        'Olea',
        'Pacuan',
        'Palapar Norte',
        'Palapar Sur',
        'Palong',
        'Pamaranum',
        'Pasima',
        'Payar',
        'Poblacion',
        'Polong Norte',
        'Polong Sur',
        'Potiocan',
        'San Julian',
        'Tabo-Sili',
        'Taloy',
        'Taloyan',
        'Talospatang',
        'Tambac',
        'Tobor',
        'Tolonguat',
        'Tomling',
        'Umando',
        'Viado',
        'Waig',
        'Warey',
    ];

    /**
     * Check if a given name is a valid barangay (case-sensitive).
     * Use this for display/utility purposes only — NOT for validation rules.
     */
    public static function isValid(string $name): bool
    {
        return in_array(trim($name), self::LIST, true);
    }

    /**
     * Returns the list sorted alphabetically (it already is, but explicit).
     *
     * @return string[]
     */
    public static function sorted(): array
    {
        $list = self::LIST;
        sort($list);
        return $list;
    }
}