<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BarangaySeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Coordinates are demo-ready map coordinates for plotting barangay points
         * on the Malasiqui map. You can refine them later using official GIS data.
         */
        $barangays = [
            ['name' => 'Abonagan', 'latitude' => 15.9510, 'longitude' => 120.4096, 'population' => 800],
            ['name' => 'Agdao', 'latitude' => 15.9440, 'longitude' => 120.4225, 'population' => 800],
            ['name' => 'Alacan', 'latitude' => 15.9280, 'longitude' => 120.3750, 'population' => 800],
            ['name' => 'Aliaga', 'latitude' => 15.9000, 'longitude' => 120.4200, 'population' => 800],
            ['name' => 'Amacalan', 'latitude' => 15.8980, 'longitude' => 120.4100, 'population' => 800],
            ['name' => 'Anolid', 'latitude' => 15.9150, 'longitude' => 120.3770, 'population' => 800],
            ['name' => 'Apaya', 'latitude' => 15.9020, 'longitude' => 120.3890, 'population' => 800],
            ['name' => 'Asin East', 'latitude' => 15.9790, 'longitude' => 120.4470, 'population' => 800],
            ['name' => 'Asin West', 'latitude' => 15.9740, 'longitude' => 120.4290, 'population' => 800],
            ['name' => 'Bacundao East', 'latitude' => 15.9360, 'longitude' => 120.4550, 'population' => 800],
            ['name' => 'Bacundao West', 'latitude' => 15.9360, 'longitude' => 120.4420, 'population' => 800],
            ['name' => 'Bakitiw', 'latitude' => 15.8870, 'longitude' => 120.4320, 'population' => 800],
            ['name' => 'Balite', 'latitude' => 15.9620, 'longitude' => 120.4480, 'population' => 800],
            ['name' => 'Banaoang', 'latitude' => 15.9290, 'longitude' => 120.4010, 'population' => 800],
            ['name' => 'Barang', 'latitude' => 15.9520, 'longitude' => 120.3930, 'population' => 800],
            ['name' => 'Bawer', 'latitude' => 15.8970, 'longitude' => 120.4020, 'population' => 800],
            ['name' => 'Binalay', 'latitude' => 15.9150, 'longitude' => 120.4050, 'population' => 800],
            ['name' => 'Bobon', 'latitude' => 15.9410, 'longitude' => 120.4110, 'population' => 800],
            ['name' => 'Bolaoit', 'latitude' => 15.9490, 'longitude' => 120.4010, 'population' => 800],
            ['name' => 'Bongar', 'latitude' => 15.8990, 'longitude' => 120.4660, 'population' => 800],
            ['name' => 'Buto', 'latitude' => 15.9150, 'longitude' => 120.4320, 'population' => 800],
            ['name' => 'Cabatling', 'latitude' => 15.9380, 'longitude' => 120.4030, 'population' => 800],
            ['name' => 'Cabueldatan', 'latitude' => 15.9090, 'longitude' => 120.3790, 'population' => 800],
            ['name' => 'Calbueg', 'latitude' => 15.9630, 'longitude' => 120.4350, 'population' => 800],
            ['name' => 'Canan Norte', 'latitude' => 15.9220, 'longitude' => 120.4420, 'population' => 800],
            ['name' => 'Canan Sur', 'latitude' => 15.9160, 'longitude' => 120.4440, 'population' => 800],
            ['name' => 'Cawayan Bogtong', 'latitude' => 15.9630, 'longitude' => 120.3740, 'population' => 800],
            ['name' => 'Don Pedro', 'latitude' => 15.8920, 'longitude' => 120.4020, 'population' => 800],
            ['name' => 'Gatang', 'latitude' => 15.8840, 'longitude' => 120.4440, 'population' => 800],
            ['name' => 'Goliman', 'latitude' => 15.8660, 'longitude' => 120.4270, 'population' => 800],
            ['name' => 'Gomez', 'latitude' => 15.9250, 'longitude' => 120.3890, 'population' => 800],
            ['name' => 'Guilig', 'latitude' => 15.9270, 'longitude' => 120.3820, 'population' => 800],
            ['name' => 'Ican', 'latitude' => 15.9640, 'longitude' => 120.3870, 'population' => 800],
            ['name' => 'Ingala-Gala', 'latitude' => 15.9130, 'longitude' => 120.4070, 'population' => 800],
            ['name' => 'Lareg-Lareg', 'latitude' => 15.9190, 'longitude' => 120.4600, 'population' => 800],
            ['name' => 'Lasip', 'latitude' => 15.9180, 'longitude' => 120.4190, 'population' => 800],
            ['name' => 'Lepa', 'latitude' => 15.8920, 'longitude' => 120.4180, 'population' => 800],
            ['name' => 'Lokeb Este', 'latitude' => 15.9680, 'longitude' => 120.4130, 'population' => 800],
            ['name' => 'Lokeb Norte', 'latitude' => 15.9660, 'longitude' => 120.4020, 'population' => 800],
            ['name' => 'Lokeb Sur', 'latitude' => 15.9550, 'longitude' => 120.4130, 'population' => 800],
            ['name' => 'Lunec', 'latitude' => 15.9640, 'longitude' => 120.4610, 'population' => 800],
            ['name' => 'Mabulitec', 'latitude' => 15.9180, 'longitude' => 120.4300, 'population' => 800],
            ['name' => 'Malimpec', 'latitude' => 15.9010, 'longitude' => 120.4340, 'population' => 800],
            ['name' => 'Manggan-Dampay', 'latitude' => 15.9480, 'longitude' => 120.4640, 'population' => 800],
            ['name' => 'Nalsian Norte', 'latitude' => 15.9040, 'longitude' => 120.4570, 'population' => 800],
            ['name' => 'Nalsian Sur', 'latitude' => 15.8960, 'longitude' => 120.4570, 'population' => 800],
            ['name' => 'Nancapian', 'latitude' => 15.9120, 'longitude' => 120.4710, 'population' => 800],
            ['name' => 'Nansangaan', 'latitude' => 15.9300, 'longitude' => 120.4300, 'population' => 800],
            ['name' => 'Olea', 'latitude' => 15.8800, 'longitude' => 120.4560, 'population' => 800],
            ['name' => 'Pacuan', 'latitude' => 15.8650, 'longitude' => 120.4430, 'population' => 800],
            ['name' => 'Palapar Norte', 'latitude' => 15.9050, 'longitude' => 120.4570, 'population' => 800],
            ['name' => 'Palapar Sur', 'latitude' => 15.8940, 'longitude' => 120.4590, 'population' => 800],
            ['name' => 'Palong', 'latitude' => 15.8830, 'longitude' => 120.4180, 'population' => 800],
            ['name' => 'Pamaranum', 'latitude' => 15.9020, 'longitude' => 120.4440, 'population' => 800],
            ['name' => 'Pasima', 'latitude' => 15.9510, 'longitude' => 120.3660, 'population' => 800],
            ['name' => 'Payar', 'latitude' => 15.9180, 'longitude' => 120.3890, 'population' => 800],
            ['name' => 'Poblacion', 'latitude' => 15.9196, 'longitude' => 120.4123, 'population' => 1200],
            ['name' => 'Polong Norte', 'latitude' => 15.9180, 'longitude' => 120.3970, 'population' => 800],
            ['name' => 'Polong Sur', 'latitude' => 15.9060, 'longitude' => 120.4010, 'population' => 800],
            ['name' => 'Potiocan', 'latitude' => 15.9810, 'longitude' => 120.4160, 'population' => 800],
            ['name' => 'San Julian', 'latitude' => 15.8820, 'longitude' => 120.4070, 'population' => 800],
            ['name' => 'Tabo-Sili', 'latitude' => 15.9530, 'longitude' => 120.4270, 'population' => 800],
            ['name' => 'Taloy', 'latitude' => 15.9320, 'longitude' => 120.3680, 'population' => 800],
            ['name' => 'Taloyan', 'latitude' => 15.9520, 'longitude' => 120.4430, 'population' => 800],
            ['name' => 'Talospatang', 'latitude' => 15.9310, 'longitude' => 120.4180, 'population' => 800],
            ['name' => 'Tambac', 'latitude' => 15.9480, 'longitude' => 120.3860, 'population' => 800],
            ['name' => 'Tobor', 'latitude' => 15.8950, 'longitude' => 120.4750, 'population' => 800],
            ['name' => 'Tolonguat', 'latitude' => 15.9840, 'longitude' => 120.4300, 'population' => 800],
            ['name' => 'Tomling', 'latitude' => 15.8750, 'longitude' => 120.4210, 'population' => 800],
            ['name' => 'Umando', 'latitude' => 15.8790, 'longitude' => 120.4340, 'population' => 800],
            ['name' => 'Viado', 'latitude' => 15.9100, 'longitude' => 120.4020, 'population' => 800],
            ['name' => 'Waig', 'latitude' => 15.8810, 'longitude' => 120.4640, 'population' => 800],
            ['name' => 'Warey', 'latitude' => 15.8720, 'longitude' => 120.4620, 'population' => 800],
        ];

        $hasLatitude = Schema::hasColumn('barangays', 'latitude');
        $hasLongitude = Schema::hasColumn('barangays', 'longitude');
        $hasPopulation = Schema::hasColumn('barangays', 'population');

        foreach ($barangays as $barangay) {
            $values = [
                'name' => $barangay['name'],
                'updated_at' => now(),
            ];

            if ($hasLatitude) {
                $values['latitude'] = $barangay['latitude'];
            }

            if ($hasLongitude) {
                $values['longitude'] = $barangay['longitude'];
            }

            if ($hasPopulation) {
                $values['population'] = $barangay['population'];
            }

            DB::table('barangays')->updateOrInsert(
                ['name' => $barangay['name']],
                $values
            );
        }

        $this->command->info('BarangaySeeder: ' . count($barangays) . ' barangays seeded with GIS coordinates.');
    }
}