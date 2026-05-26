<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds latitude, longitude, and estimated population for all 73
 * barangays of Malasiqui, Pangasinan.
 *
 * Coordinates are approximate geographic centroids suitable for
 * GIS heatmap rendering.  Population estimates are based on
 * 2020 PSA Census projections and are used to normalize incidence
 * rates (cases per 1,000 residents).
 *
 * Run:  php artisan db:seed --class=BarangayCoordinatesSeeder
 */
class BarangayCoordinatesSeeder extends Seeder
{
    public function run(): void
    {
        // Centre of Malasiqui as default fallback
        $defaultLat = 15.9196;
        $defaultLng = 120.4123;

        $coordinates = [
            'Abonagan'         => ['lat' => 15.9350, 'lng' => 120.4300, 'pop' => 1200],
            'Agdao'            => ['lat' => 15.9280, 'lng' => 120.4250, 'pop' => 850],
            'Alacan'           => ['lat' => 15.9150, 'lng' => 120.3950, 'pop' => 950],
            'Aliaga'           => ['lat' => 15.9100, 'lng' => 120.4350, 'pop' => 1100],
            'Amacalan'         => ['lat' => 15.9400, 'lng' => 120.4180, 'pop' => 1050],
            'Anolid'           => ['lat' => 15.9320, 'lng' => 120.4050, 'pop' => 980],
            'Apaya'            => ['lat' => 15.9050, 'lng' => 120.4200, 'pop' => 750],
            'Asin East'        => ['lat' => 15.9220, 'lng' => 120.4280, 'pop' => 600],
            'Asin West'        => ['lat' => 15.9210, 'lng' => 120.4200, 'pop' => 580],
            'Bacundao East'    => ['lat' => 15.9130, 'lng' => 120.4100, 'pop' => 1400],
            'Bacundao West'    => ['lat' => 15.9120, 'lng' => 120.4000, 'pop' => 1350],
            'Bakitiw'          => ['lat' => 15.9380, 'lng' => 120.4350, 'pop' => 700],
            'Balite'           => ['lat' => 15.9300, 'lng' => 120.4400, 'pop' => 820],
            'Banaoang'         => ['lat' => 15.9080, 'lng' => 120.3900, 'pop' => 1100],
            'Barang'           => ['lat' => 15.9250, 'lng' => 120.4150, 'pop' => 900],
            'Bawer'            => ['lat' => 15.9270, 'lng' => 120.4000, 'pop' => 950],
            'Binalay'          => ['lat' => 15.9330, 'lng' => 120.4100, 'pop' => 1050],
            'Bobon'            => ['lat' => 15.9360, 'lng' => 120.3950, 'pop' => 780],
            'Bolaoit'          => ['lat' => 15.9180, 'lng' => 120.3850, 'pop' => 1200],
            'Bongar'           => ['lat' => 15.9070, 'lng' => 120.4050, 'pop' => 650],
            'Buto'             => ['lat' => 15.9030, 'lng' => 120.4150, 'pop' => 700],
            'Cabatling'        => ['lat' => 15.9200, 'lng' => 120.4350, 'pop' => 1300],
            'Cabueldatan'      => ['lat' => 15.9160, 'lng' => 120.4250, 'pop' => 900],
            'Calbueg'          => ['lat' => 15.9140, 'lng' => 120.4180, 'pop' => 850],
            'Canan Norte'      => ['lat' => 15.9260, 'lng' => 120.4080, 'pop' => 1150],
            'Canan Sur'        => ['lat' => 15.9240, 'lng' => 120.4070, 'pop' => 1100],
            'Cawayan Bogtong'  => ['lat' => 15.9340, 'lng' => 120.4200, 'pop' => 800],
            'Don Pedro'        => ['lat' => 15.9090, 'lng' => 120.4300, 'pop' => 750],
            'Gatang'           => ['lat' => 15.9310, 'lng' => 120.3900, 'pop' => 1050],
            'Goliman'          => ['lat' => 15.9370, 'lng' => 120.4000, 'pop' => 680],
            'Gomez'            => ['lat' => 15.9290, 'lng' => 120.4320, 'pop' => 720],
            'Guilig'           => ['lat' => 15.9110, 'lng' => 120.3980, 'pop' => 890],
            'Ican'             => ['lat' => 15.9040, 'lng' => 120.4080, 'pop' => 760],
            'Ingala-Gala'      => ['lat' => 15.9060, 'lng' => 120.4250, 'pop' => 650],
            'Lasip'            => ['lat' => 15.9170, 'lng' => 120.3920, 'pop' => 980],
            'Lareg-Lareg'      => ['lat' => 15.9190, 'lng' => 120.3880, 'pop' => 600],
            'Lepa'             => ['lat' => 15.9230, 'lng' => 120.3860, 'pop' => 750],
            'Lokeb Este'       => ['lat' => 15.9350, 'lng' => 120.4150, 'pop' => 820],
            'Lokeb Norte'      => ['lat' => 15.9370, 'lng' => 120.4120, 'pop' => 780],
            'Lokeb Sur'        => ['lat' => 15.9340, 'lng' => 120.4170, 'pop' => 800],
            'Lunec'            => ['lat' => 15.9200, 'lng' => 120.4100, 'pop' => 1500],
            'Manggan-Dampay'   => ['lat' => 15.9280, 'lng' => 120.3930, 'pop' => 680],
            'Mabulitec'        => ['lat' => 15.9250, 'lng' => 120.3870, 'pop' => 720],
            'Malimpec'         => ['lat' => 15.9300, 'lng' => 120.3840, 'pop' => 900],
            'Nalsian Norte'    => ['lat' => 15.9210, 'lng' => 120.4050, 'pop' => 1250],
            'Nalsian Sur'      => ['lat' => 15.9190, 'lng' => 120.4030, 'pop' => 1200],
            'Nancapian'        => ['lat' => 15.9160, 'lng' => 120.3960, 'pop' => 800],
            'Nansangaan'       => ['lat' => 15.9130, 'lng' => 120.4150, 'pop' => 700],
            'Olea'             => ['lat' => 15.9100, 'lng' => 120.4200, 'pop' => 850],
            'Pacuan'           => ['lat' => 15.9070, 'lng' => 120.3950, 'pop' => 780],
            'Palapar Norte'    => ['lat' => 15.9320, 'lng' => 120.4280, 'pop' => 650],
            'Palapar Sur'      => ['lat' => 15.9300, 'lng' => 120.4270, 'pop' => 620],
            'Palong'           => ['lat' => 15.9340, 'lng' => 120.4050, 'pop' => 700],
            'Pamaranum'        => ['lat' => 15.9270, 'lng' => 120.4380, 'pop' => 580],
            'Pasima'           => ['lat' => 15.9180, 'lng' => 120.4300, 'pop' => 900],
            'Payar'            => ['lat' => 15.9390, 'lng' => 120.4100, 'pop' => 650],
            'Poblacion'        => ['lat' => 15.9196, 'lng' => 120.4123, 'pop' => 3500],
            'Polong Norte'     => ['lat' => 15.9240, 'lng' => 120.4200, 'pop' => 750],
            'Polong Sur'       => ['lat' => 15.9220, 'lng' => 120.4190, 'pop' => 720],
            'Potiocan'         => ['lat' => 15.9150, 'lng' => 120.4080, 'pop' => 680],
            'San Julian'       => ['lat' => 15.9120, 'lng' => 120.4120, 'pop' => 950],
            'Tabo-Sili'        => ['lat' => 15.9080, 'lng' => 120.4000, 'pop' => 800],
            'Taloy'            => ['lat' => 15.9060, 'lng' => 120.4100, 'pop' => 720],
            'Taloyan'          => ['lat' => 15.9050, 'lng' => 120.4050, 'pop' => 680],
            'Talospatang'      => ['lat' => 15.9040, 'lng' => 120.3900, 'pop' => 600],
            'Tambac'           => ['lat' => 15.9380, 'lng' => 120.3980, 'pop' => 850],
            'Tobor'            => ['lat' => 15.9360, 'lng' => 120.4250, 'pop' => 780],
            'Tolonguat'        => ['lat' => 15.9310, 'lng' => 120.4330, 'pop' => 650],
            'Tomling'          => ['lat' => 15.9290, 'lng' => 120.3960, 'pop' => 700],
            'Umando'           => ['lat' => 15.9260, 'lng' => 120.3840, 'pop' => 550],
            'Viado'            => ['lat' => 15.9230, 'lng' => 120.4400, 'pop' => 620],
            'Waig'             => ['lat' => 15.9170, 'lng' => 120.3840, 'pop' => 580],
            'Warey'            => ['lat' => 15.9140, 'lng' => 120.3870, 'pop' => 640],
        ];

        foreach ($coordinates as $name => $data) {
            DB::table('barangays')
                ->where('name', $name)
                ->update([
                    'latitude'   => $data['lat'],
                    'longitude'  => $data['lng'],
                    'population' => $data['pop'],
                ]);
        }

        // Set defaults for any barangays not in the list above
        DB::table('barangays')
            ->whereNull('latitude')
            ->update([
                'latitude'   => $defaultLat,
                'longitude'  => $defaultLng,
                'population' => 800,
            ]);
    }
}
