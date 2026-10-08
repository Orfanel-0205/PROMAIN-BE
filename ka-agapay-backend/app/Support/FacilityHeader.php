<?php
// app/Support/FacilityHeader.php
//
// THE ISSUING RHU, AS PRINTED on an e-prescription or a lab request.
//
// Settings → Facility Information (per RHU: name, address, contact number,
// email, opening hours) was saved and read by nothing; every PDF said "RHU
// Malasiqui", whichever RHU issued it. Now each field comes from that RHU's
// Facility Information, falling back to its record under Administration →
// RHU Facilities (name, address, contact number), then to the old wording,
// so a PDF is never left with a blank header.

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FacilityHeader
{
    /**
     * @return array{rhuName: string, rhuAddress: ?string, rhuContact: ?string, rhuEmail: ?string, rhuHours: ?string, municipality: string}
     */
    public static function for(?int $rhuId): array
    {
        $rhuId = Rhu::normalizeRhuId($rhuId) ?? Rhu::defaultId();
        $settings = AppSettings::section(AppSetting::GROUP_FACILITY, $rhuId);

        $record = Schema::hasTable('rhus')
            ? DB::table('rhus')->where('id', $rhuId)->first()
            : null;

        $first = static function (...$values): ?string {
            foreach ($values as $value) {
                $text = trim((string) ($value ?? ''));

                if ($text !== '') {
                    return $text;
                }
            }

            return null;
        };

        return [
            'rhuName' => $first($settings['facility_name'] ?? null, $record->name ?? null) ?? 'RHU Malasiqui',
            'rhuAddress' => $first($settings['address'] ?? null, $record->address ?? null),
            'rhuContact' => $first($settings['contact_number'] ?? null, $record->contact_number ?? null),
            'rhuEmail' => $first($settings['email'] ?? null),
            'rhuHours' => $first($settings['operating_hours'] ?? null),
            'municipality' => 'Malasiqui, Pangasinan',
        ];
    }
}
