<?php
// database/seeders/BarangaySeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BarangaySeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('barangays')) {
            $this->command?->warn('barangays table does not exist.');
            return;
        }

        $hasLatitude = Schema::hasColumn('barangays', 'latitude');
        $hasLongitude = Schema::hasColumn('barangays', 'longitude');
        $hasPopulation = Schema::hasColumn('barangays', 'population');

        $barangays = [
            ['name' => 'Abonagan', 'latitude' => 15.9312, 'longitude' => 120.4393, 'population' => 1200],
            ['name' => 'Agdao', 'latitude' => 15.9221, 'longitude' => 120.4573, 'population' => 850],
            ['name' => 'Alacan', 'latitude' => 15.9115, 'longitude' => 120.4087, 'population' => 950],
            ['name' => 'Aliaga', 'latitude' => 15.8897, 'longitude' => 120.4489, 'population' => 1100],
            ['name' => 'Amacalan', 'latitude' => 15.8850, 'longitude' => 120.4418, 'population' => 1050],
            ['name' => 'Anolid', 'latitude' => 15.9002, 'longitude' => 120.4036, 'population' => 980],
            ['name' => 'Apaya', 'latitude' => 15.8938, 'longitude' => 120.4228, 'population' => 750],
            ['name' => 'Asin East', 'latitude' => 15.9605, 'longitude' => 120.4687, 'population' => 600],
            ['name' => 'Asin West', 'latitude' => 15.9575, 'longitude' => 120.4566, 'population' => 580],
            ['name' => 'Bacundao East', 'latitude' => 15.9210, 'longitude' => 120.4848, 'population' => 1400],
            ['name' => 'Bacundao West', 'latitude' => 15.9195, 'longitude' => 120.4737, 'population' => 1350],
            ['name' => 'Bakitiw', 'latitude' => 15.8781, 'longitude' => 120.4592, 'population' => 700],
            ['name' => 'Balite', 'latitude' => 15.9533, 'longitude' => 120.4790, 'population' => 820],
            ['name' => 'Banaoang', 'latitude' => 15.9096, 'longitude' => 120.4277, 'population' => 1100],
            ['name' => 'Barang', 'latitude' => 15.9352, 'longitude' => 120.4183, 'population' => 900],
            ['name' => 'Bawer', 'latitude' => 15.8942, 'longitude' => 120.4319, 'population' => 950],
            ['name' => 'Binalay', 'latitude' => 15.9067, 'longitude' => 120.4300, 'population' => 1050],
            ['name' => 'Bobon', 'latitude' => 15.9229, 'longitude' => 120.4444, 'population' => 780],
            ['name' => 'Bolaoit', 'latitude' => 15.9329, 'longitude' => 120.4310, 'population' => 1200],
            ['name' => 'Bongar', 'latitude' => 15.8833, 'longitude' => 120.5040, 'population' => 650],
            ['name' => 'Buto', 'latitude' => 15.9099, 'longitude' => 120.4599, 'population' => 700],
            ['name' => 'Cabatling', 'latitude' => 15.9197, 'longitude' => 120.4354, 'population' => 1300],
            ['name' => 'Cabueldatan', 'latitude' => 15.8952, 'longitude' => 120.4072, 'population' => 900],
            ['name' => 'Calbueg', 'latitude' => 15.9455, 'longitude' => 120.4640, 'population' => 850],
            ['name' => 'Canan Norte', 'latitude' => 15.9113, 'longitude' => 120.4737, 'population' => 1150],
            ['name' => 'Canan Sur', 'latitude' => 15.9029, 'longitude' => 120.4782, 'population' => 1100],
            ['name' => 'Cawayan Bogtong', 'latitude' => 15.9414, 'longitude' => 120.4039, 'population' => 800],
            ['name' => 'Don Pedro', 'latitude' => 15.8847, 'longitude' => 120.4310, 'population' => 750],
            ['name' => 'Gatang', 'latitude' => 15.8638, 'longitude' => 120.4785, 'population' => 1050],
            ['name' => 'Goliman', 'latitude' => 15.8619, 'longitude' => 120.4561, 'population' => 680],
            ['name' => 'Gomez', 'latitude' => 15.9109, 'longitude' => 120.4162, 'population' => 720],
            ['name' => 'Guilig', 'latitude' => 15.9146, 'longitude' => 120.4029, 'population' => 890],
            ['name' => 'Ican', 'latitude' => 15.9450, 'longitude' => 120.4104, 'population' => 760],
            ['name' => 'Ingala-Gala', 'latitude' => 15.8981, 'longitude' => 120.4357, 'population' => 650],
            ['name' => 'Lareg-Lareg', 'latitude' => 15.9149, 'longitude' => 120.4994, 'population' => 600],
            ['name' => 'Lasip', 'latitude' => 15.9085, 'longitude' => 120.4495, 'population' => 980],
            ['name' => 'Lepa', 'latitude' => 15.8776, 'longitude' => 120.4490, 'population' => 750],
            ['name' => 'Lokeb Este', 'latitude' => 15.9484, 'longitude' => 120.4396, 'population' => 820],
            ['name' => 'Lokeb Norte', 'latitude' => 15.9428, 'longitude' => 120.4323, 'population' => 780],
            ['name' => 'Lokeb Sur', 'latitude' => 15.9414, 'longitude' => 120.4444, 'population' => 800],
            ['name' => 'Lunec', 'latitude' => 15.9537, 'longitude' => 120.4915, 'population' => 1500],
            ['name' => 'Mabulitec', 'latitude' => 15.8892, 'longitude' => 120.4088, 'population' => 720],
            ['name' => 'Malimpec', 'latitude' => 15.8941, 'longitude' => 120.4615, 'population' => 900],
            ['name' => 'Manggan-Dampay', 'latitude' => 15.9352, 'longitude' => 120.4941, 'population' => 680],
            ['name' => 'Nalsian Norte', 'latitude' => 15.8601, 'longitude' => 120.4415, 'population' => 1250],
            ['name' => 'Nalsian Sur', 'latitude' => 15.8487, 'longitude' => 120.4389, 'population' => 1200],
            ['name' => 'Nancapian', 'latitude' => 15.8991, 'longitude' => 120.5084, 'population' => 800],
            ['name' => 'Nansangaan', 'latitude' => 15.9182, 'longitude' => 120.4616, 'population' => 700],
            ['name' => 'Olea', 'latitude' => 15.8615, 'longitude' => 120.4864, 'population' => 850],
            ['name' => 'Pacuan', 'latitude' => 15.8559, 'longitude' => 120.4667, 'population' => 780],
            ['name' => 'Palapar Norte', 'latitude' => 15.8916, 'longitude' => 120.4891, 'population' => 650],
            ['name' => 'Palapar Sur', 'latitude' => 15.8876, 'longitude' => 120.4959, 'population' => 620],
            ['name' => 'Palong', 'latitude' => 15.8701, 'longitude' => 120.4392, 'population' => 700],
            ['name' => 'Pamaranum', 'latitude' => 15.8940, 'longitude' => 120.4830, 'population' => 580],
            ['name' => 'Pasima', 'latitude' => 15.9330, 'longitude' => 120.3944, 'population' => 900],
            ['name' => 'Payar', 'latitude' => 15.9066, 'longitude' => 120.4162, 'population' => 650],
            ['name' => 'Poblacion', 'latitude' => 15.9202, 'longitude' => 120.4145, 'population' => 3500],
            ['name' => 'Polong Norte', 'latitude' => 15.9021, 'longitude' => 120.4218, 'population' => 750],
            ['name' => 'Polong Sur', 'latitude' => 15.8932, 'longitude' => 120.4270, 'population' => 720],
            ['name' => 'Potiocan', 'latitude' => 15.9514, 'longitude' => 120.4253, 'population' => 680],
            ['name' => 'San Julian', 'latitude' => 15.8692, 'longitude' => 120.4367, 'population' => 950],
            ['name' => 'Tabo-Sili', 'latitude' => 15.9316, 'longitude' => 120.4483, 'population' => 800],
            ['name' => 'Talospatang', 'latitude' => 15.9182, 'longitude' => 120.4516, 'population' => 600],
            ['name' => 'Taloy', 'latitude' => 15.9223, 'longitude' => 120.4008, 'population' => 720],
            ['name' => 'Taloyan', 'latitude' => 15.9358, 'longitude' => 120.4708, 'population' => 680],
            ['name' => 'Tambac', 'latitude' => 15.9315, 'longitude' => 120.4102, 'population' => 850],
            ['name' => 'Tobor', 'latitude' => 15.8835, 'longitude' => 120.5113, 'population' => 780],
            ['name' => 'Tolonguat', 'latitude' => 15.9657, 'longitude' => 120.4529, 'population' => 650],
            ['name' => 'Tomling', 'latitude' => 15.8638, 'longitude' => 120.4520, 'population' => 700],
            ['name' => 'Umando', 'latitude' => 15.8676, 'longitude' => 120.4603, 'population' => 550],
            ['name' => 'Viado', 'latitude' => 15.9025, 'longitude' => 120.4300, 'population' => 620],
            ['name' => 'Waig', 'latitude' => 15.8660, 'longitude' => 120.5000, 'population' => 580],
            ['name' => 'Warey', 'latitude' => 15.8707, 'longitude' => 120.4302, 'population' => 640],
        ];

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
                array_merge(['created_at' => now()], $values)
            );
        }

        $this->command?->info('BarangaySeeder: seeded ' . count($barangays) . ' Malasiqui barangays with corrected GIS coordinates.');
    }
}