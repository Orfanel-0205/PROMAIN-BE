<?php
// database/seeders/BarangayCoordinatesSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds verified barangay latitude and longitude for Malasiqui, Pangasinan.
 *
 * Source:
 * - Barangay coordinates checked from Google Maps.
 *
 * Real-life safety logic:
 * - Updates existing barangays only.
 * - Does not create duplicate barangays.
 * - Does not use fake default coordinates.
 * - Supports old/new barangay spelling such as Asin East/Asin Este,
 *   Banaoang/Banawang, Buto/Butao, Lokeb/Loqueb.
 * - Marks coordinate_source when the column exists.
 *
 * Run:
 * php artisan db:seed --class=BarangayCoordinatesSeeder
 */
class BarangayCoordinatesSeeder extends Seeder
{
    private function key(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($name))) ?: '';
    }

    private function isValidCoordinate(float $lat, float $lng): bool
    {
        return $lat >= -90 &&
            $lat <= 90 &&
            $lng >= -180 &&
            $lng <= 180 &&
            $lat !== 0.0 &&
            $lng !== 0.0;
    }

    public function run(): void
    {
        if (!Schema::hasTable('barangays')) {
            $this->command?->error('The barangays table does not exist.');
            return;
        }

        if (
            !Schema::hasColumn('barangays', 'latitude') ||
            !Schema::hasColumn('barangays', 'longitude')
        ) {
            $this->command?->error(
                'The barangays table must have latitude and longitude columns before running this seeder.'
            );
            return;
        }

        $primaryKey = Schema::hasColumn('barangays', 'barangay_id')
            ? 'barangay_id'
            : 'id';

        $coordinates = [
            'Abonagan'        => ['lat' => 15.9372, 'lng' => 120.4077],
            'Agdao'           => ['lat' => 15.9189, 'lng' => 120.4005],
            'Alacan'          => ['lat' => 15.9555, 'lng' => 120.4461],
            'Aliaga'          => ['lat' => 15.8941, 'lng' => 120.4184],
            'Amacalan'        => ['lat' => 15.9080, 'lng' => 120.4215],
            'Anolid'          => ['lat' => 15.9587, 'lng' => 120.4358],
            'Apaya'           => ['lat' => 15.9507, 'lng' => 120.3951],
            'Asin East'       => ['lat' => 15.9647, 'lng' => 120.4794],
            'Asin West'       => ['lat' => 15.9602, 'lng' => 120.4612],
            'Bacundao East'   => ['lat' => 15.9026, 'lng' => 120.4363],
            'Bacundao West'   => ['lat' => 15.9008, 'lng' => 120.4284],
            'Bakitiw'         => ['lat' => 15.9389, 'lng' => 120.4682],
            'Balite'          => ['lat' => 15.9427, 'lng' => 120.3980],
            'Banaoang'        => ['lat' => 15.9458, 'lng' => 120.4578],
            'Barang'          => ['lat' => 15.9304, 'lng' => 120.3804],
            'Bawer'           => ['lat' => 15.9388, 'lng' => 120.3752],
            'Binalay'         => ['lat' => 15.9584, 'lng' => 120.4190],
            'Bobon'           => ['lat' => 15.9515, 'lng' => 120.4124],
            'Bolaoit'         => ['lat' => 15.9757, 'lng' => 120.4206],
            'Bongar'          => ['lat' => 15.9610, 'lng' => 120.4607],
            'Buto'            => ['lat' => 15.9189, 'lng' => 120.3768],
            'Cabatling'       => ['lat' => 15.9554, 'lng' => 120.4673],
            'Cabueldatan'     => ['lat' => 15.9171, 'lng' => 120.4439],
            'Calbueg'         => ['lat' => 15.9037, 'lng' => 120.3854],
            'Canan Norte'     => ['lat' => 15.9392, 'lng' => 120.3916],
            'Canan Sur'       => ['lat' => 15.9254, 'lng' => 120.3934],
            'Cawayan Bogtong' => ['lat' => 15.8903, 'lng' => 120.4357],
            'Don Pedro'       => ['lat' => 15.9264, 'lng' => 120.4447],
            'Gatang'          => ['lat' => 15.8812, 'lng' => 120.4468],
            'Goliman'         => ['lat' => 15.8967, 'lng' => 120.4504],
            'Gomez'           => ['lat' => 15.9103, 'lng' => 120.3939],
            'Guilig'          => ['lat' => 15.9220, 'lng' => 120.4087],
            'Ican'            => ['lat' => 15.9413, 'lng' => 120.4302],
            'Ingala-Gala'     => ['lat' => 15.8997, 'lng' => 120.4104],
            'Lareg-Lareg'     => ['lat' => 15.9461, 'lng' => 120.4851],
            'Lasip'           => ['lat' => 15.9213, 'lng' => 120.3846],
            'Lepa'            => ['lat' => 15.9529, 'lng' => 120.4262],
            'Lokeb Este'      => ['lat' => 15.9192, 'lng' => 120.4709],
            'Lokeb Norte'     => ['lat' => 15.9265, 'lng' => 120.4735],
            'Lokeb Sur'       => ['lat' => 15.9126, 'lng' => 120.4764],
            'Lunec'           => ['lat' => 15.9472, 'lng' => 120.4077],
            'Mabulitec'       => ['lat' => 15.9482, 'lng' => 120.3845],
            'Malimpec'        => ['lat' => 15.9133, 'lng' => 120.4011],
            'Manggan-Dampay'  => ['lat' => 15.8950, 'lng' => 120.3995],
            'Nalsian Norte'   => ['lat' => 15.9691, 'lng' => 120.4347],
            'Nalsian Sur'     => ['lat' => 15.9631, 'lng' => 120.4342],
            'Nancapian'       => ['lat' => 15.8856, 'lng' => 120.4124],
            'Nansangaan'      => ['lat' => 15.9683, 'lng' => 120.4437],
            'Olea'            => ['lat' => 15.9332, 'lng' => 120.4503],
            'Pacuan'          => ['lat' => 15.8956, 'lng' => 120.3871],
            'Palapar Norte'   => ['lat' => 15.9080, 'lng' => 120.4633],
            'Palapar Sur'     => ['lat' => 15.8973, 'lng' => 120.4651],
            'Palong'          => ['lat' => 15.9416, 'lng' => 120.4485],
            'Pamaranum'       => ['lat' => 15.8679, 'lng' => 120.4069],
            'Pasima'          => ['lat' => 15.8752, 'lng' => 120.4187],
            'Payar'           => ['lat' => 15.9264, 'lng' => 120.4608],
            'Poblacion'       => ['lat' => 15.9298, 'lng' => 120.4194],
            'Polong Norte'    => ['lat' => 15.9189, 'lng' => 120.4217],
            'Polong Sur'      => ['lat' => 15.9137, 'lng' => 120.4285],
            'Potiocan'        => ['lat' => 15.8817, 'lng' => 120.4259],
            'San Julian'      => ['lat' => 15.9238, 'lng' => 120.4326],
            'Tabo-Sili'       => ['lat' => 15.9317, 'lng' => 120.4487],
            'Taloy'           => ['lat' => 15.9526, 'lng' => 120.4449],
            'Taloyan'         => ['lat' => 15.8856, 'lng' => 120.4551],
            'Talospatang'     => ['lat' => 15.9723, 'lng' => 120.4556],
            'Tambac'          => ['lat' => 15.9262, 'lng' => 120.3725],
            'Tobor'           => ['lat' => 15.9077, 'lng' => 120.4475],
            'Tolonguat'       => ['lat' => 15.8924, 'lng' => 120.3752],
            'Tomling'         => ['lat' => 15.9546, 'lng' => 120.4539],
            'Umando'          => ['lat' => 15.8885, 'lng' => 120.3894],
            'Viado'           => ['lat' => 15.9333, 'lng' => 120.4005],
            'Waig'            => ['lat' => 15.8804, 'lng' => 120.4354],
            'Warey'           => ['lat' => 15.9169, 'lng' => 120.4542],
        ];

        $aliases = [
            'Asin Este'      => 'Asin East',
            'Asin East'      => 'Asin East',
            'Asin Weste'     => 'Asin West',
            'Asin West'      => 'Asin West',

            'Banaoang'       => 'Banaoang',
            'Banawang'       => 'Banaoang',

            'Buto'           => 'Buto',
            'Butao'          => 'Buto',

            'Ingala-Gala'    => 'Ingala-Gala',
            'Ingalagala'     => 'Ingala-Gala',
            'Ingala Gala'    => 'Ingala-Gala',

            'Lareg-Lareg'    => 'Lareg-Lareg',
            'Lareg Lareg'    => 'Lareg-Lareg',
            'Lareglareg'     => 'Lareg-Lareg',

            'Lokeb Este'     => 'Lokeb Este',
            'Lokeb Norte'    => 'Lokeb Norte',
            'Lokeb Sur'      => 'Lokeb Sur',
            'Loqueb Este'    => 'Lokeb Este',
            'Loqueb Norte'   => 'Lokeb Norte',
            'Loqueb Sur'     => 'Lokeb Sur',
            'Loqueb East'    => 'Lokeb Este',
            'Loqueb North'   => 'Lokeb Norte',
            'Loqueb South'   => 'Lokeb Sur',

            'Tabo-Sili'      => 'Tabo-Sili',
            'Tabo Sili'      => 'Tabo-Sili',
            'Tabosili'       => 'Tabo-Sili',

            'Manggan-Dampay' => 'Manggan-Dampay',
            'Manggan Dampay' => 'Manggan-Dampay',
            'MangganDampay'  => 'Manggan-Dampay',
        ];

        $coordinateByKey = [];

        foreach ($coordinates as $name => $data) {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];

            if (!$this->isValidCoordinate($lat, $lng)) {
                $this->command?->warn("Skipped invalid coordinate for {$name}.");
                continue;
            }

            $coordinateByKey[$this->key($name)] = [
                'canonical_name' => $name,
                'lat' => $lat,
                'lng' => $lng,
            ];
        }

        foreach ($aliases as $alias => $canonicalName) {
            if (!isset($coordinates[$canonicalName])) {
                continue;
            }

            $coordinateByKey[$this->key($alias)] = [
                'canonical_name' => $canonicalName,
                'lat' => (float) $coordinates[$canonicalName]['lat'],
                'lng' => (float) $coordinates[$canonicalName]['lng'],
            ];
        }

        $barangays = DB::table('barangays')
            ->select($primaryKey, 'name')
            ->orderBy('name')
            ->get();

        $updated = 0;
        $missing = [];

        DB::transaction(function () use (
            $barangays,
            $coordinateByKey,
            $primaryKey,
            &$updated,
            &$missing
        ) {
            foreach ($barangays as $barangay) {
                $barangayName = (string) $barangay->name;
                $match = $coordinateByKey[$this->key($barangayName)] ?? null;

                if (!$match) {
                    $missing[] = $barangayName;
                    continue;
                }

                $payload = [
                    'latitude' => $match['lat'],
                    'longitude' => $match['lng'],
                ];

                if (Schema::hasColumn('barangays', 'coordinate_source')) {
                    $payload['coordinate_source'] = 'google_maps_barangay_coordinate';
                }

                if (Schema::hasColumn('barangays', 'updated_at')) {
                    $payload['updated_at'] = now();
                }

                DB::table('barangays')
                    ->where($primaryKey, $barangay->{$primaryKey})
                    ->update($payload);

                $updated++;
            }
        });

        $this->command?->info("✅ Updated coordinates for {$updated} barangays.");

        if (!empty($missing)) {
            $this->command?->warn('Barangays not matched: ' . implode(', ', $missing));
            $this->command?->warn('No fallback coordinate was applied. This prevents wrong heatmap pins.');
        }
    }
}