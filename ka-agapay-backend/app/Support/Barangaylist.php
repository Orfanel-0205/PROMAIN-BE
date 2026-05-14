<?php

namespace App\Support;

/**
 * Fixed list of barangays for Ka-Agapay.
 * Used in validation rules across the application.
 */
final class BarangayList
{
    public const LIST = [
    'Abonagan', 'Agdao', 'Alacan', 'Aliaga', 'Amacalan', 'Anolid',
    'Apaya', 'Asin Este', 'Asin Weste', 'Bacundao Este', 'Bacundao Weste',
    'Bakitiw', 'Balite', 'Banawang', 'Barang', 'Bawer', 'Binalay',
    'Bobon', 'Bolaoit', 'Bongar', 'Butao', 'Cabatling', 'Cabueldatan',
    'Calbueg', 'Canan Norte', 'Canan Sur', 'Cawayan Bogtong',
    'Don Pedro', 'Gatang', 'Goliman', 'Gomez', 'Guilig', 'Ican',
    'Ingalagala', 'Lareg-lareg', 'Lasip', 'Lepa', 'Loqueb Este',
    'Loqueb Norte', 'Loqueb Sur', 'Lunec', 'Mabulitec', 'Malimpec',
    'Manggan-Dampay', 'Nancapian', 'Nalsian Norte', 'Nalsian Sur',
    'Nansangaan', 'Olea', 'Pacuan', 'Palapar Norte', 'Palapar Sur',
    'Palong', 'Pamaranum', 'Pasima', 'Payar', 'Poblacion',
    'Polong Norte', 'Polong Sur', 'Potiocan', 'San Julian', 'Tabo-Sili',
    'Tobor', 'Talospatang', 'Taloy', 'Taloyan', 'Tambac', 'Tolonguat',
    'Tomling', 'Umando', 'Viado', 'Waig', 'Warey',
];

    /**
     * Return the list as a comma-separated string for use in
     * Laravel's 'in' validation rule.
     */
    public static function validationRule(): string
    {
        return 'in:' . implode(',', self::LIST);
    }

    /**
     * Check if a given name is a valid barangay (case-sensitive).
     */
    public static function isValid(string $name): bool
    {
        return in_array($name, self::LIST, true);
    }
}