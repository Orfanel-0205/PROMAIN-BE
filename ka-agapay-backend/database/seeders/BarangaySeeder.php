<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
            'Lasip',
            'Lareg-Lareg',
            'Lepa',
            'Lokeb Este',
            'Lokeb Norte',
            'Lokeb Sur',
            'Lunec',
            'Manggan-Dampay',
            'Mabulitec',
            'Malimpec',
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
            DB::table('barangays')->insertOrIgnore(['name' => $name]);
        }
    }
}