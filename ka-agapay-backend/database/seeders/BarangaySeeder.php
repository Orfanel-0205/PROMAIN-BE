<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds all 73 barangays of Malasiqui, Pangasinan.
 *
 * ── AUTHORITATIVE SOURCE ────────────────────────────────────────────────────
 * This seeder is the single source of truth for barangay name spellings.
 * The following MUST always match this list exactly:
 *
 *   • app/Support/BarangayList.php      (utility list — same spellings)
 *   • constants/barangay.ts             (frontend fallback — same spellings)
 *
 * Validation uses Rule::exists('barangays', 'name') — NOT hardcoded lists.
 *
 * After any change to names, run:
 *   php artisan cache:forget barangays_list
 *   php artisan db:seed --class=BarangaySeeder
 * ───────────────────────────────────────────────────────────────────────────
 */
class BarangaySeeder extends Seeder
{
    public function run(): void
    {
        $barangays = [
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

        foreach ($barangays as $name) {
            DB::table('barangays')->insertOrIgnore(['name' => trim($name)]);
        }

        $this->command->info('BarangaySeeder: ' . count($barangays) . ' barangays seeded.');
    }
}