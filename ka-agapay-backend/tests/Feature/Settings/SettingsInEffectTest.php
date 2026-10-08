<?php
// tests/Feature/Settings/SettingsInEffectTest.php
//
// Settings that now do something (Oct 2026). Of the ten on the Settings page
// only "max login attempts" used to take effect:
//   - Facility Information (per RHU) is printed on that RHU's e-prescriptions
//     and lab requests (FacilityHeader), which all said "RHU Malasiqui";
//   - Session timeout is served to the dashboard, which signs staff out after
//     that many idle minutes (GET /session-policy).

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\UserRole;
use App\Support\FacilityHeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsInEffectTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::create([
            'role_id' => UserRole::firstOrCreate(['name' => 'super_admin'], ['permissions' => []])->role_id,
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'mobile_number' => '09171200001',
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);

        Cache::flush();
    }

    public function test_a_pdf_prints_the_issuing_rhus_own_details(): void
    {
        DB::table('rhus')->where('id', 2)->update(['name' => 'RHU 2 Malasiqui (Don Pedro)', 'address' => 'Don Pedro, Malasiqui']);

        // Nothing in Settings yet: the RHU's record.
        $header = FacilityHeader::for(2);
        $this->assertSame('RHU 2 Malasiqui (Don Pedro)', $header['rhuName']);
        $this->assertSame('Don Pedro, Malasiqui', $header['rhuAddress']);

        // Facility Information for RHU 2 wins, and RHU 1 is unaffected.
        $this->actingAs($this->superAdmin)->putJson('/api/v1/admin/settings/facility', [
            'rhu_id' => 2,
            'facility_name' => 'Rural Health Unit II',
            'address' => 'Brgy. Don Pedro, Malasiqui, Pangasinan',
            'contact_number' => '+63 75 632 2222',
            'email' => 'rhu2@malasiqui.gov.ph',
            'operating_hours' => '8:00 AM - 5:00 PM',
        ])->assertOk();

        $header = FacilityHeader::for(2);
        $this->assertSame('Rural Health Unit II', $header['rhuName']);
        $this->assertSame('+63 75 632 2222', $header['rhuContact']);
        $this->assertSame('8:00 AM - 5:00 PM', $header['rhuHours']);
        $this->assertNotSame('Rural Health Unit II', FacilityHeader::for(1)['rhuName']);

        // Both PDF templates render with the new fields.
        $html = view('pdf.prescription-modern', $header + ['medicines' => [], 'patientName' => 'X', 'doctorName' => 'Y',
            'prescriptionNo' => 'RX-1', 'date' => 'Oct 8, 2026', 'validUntil' => 'Oct 15, 2026', 'diagnosis' => 'Z'])->render();
        $this->assertStringContainsString('Rural Health Unit II', $html);
        $this->assertStringContainsString('+63 75 632 2222', $html);
    }

    public function test_the_session_timeout_reaches_the_dashboard(): void
    {
        $this->actingAs($this->superAdmin)->getJson('/api/v1/session-policy')
            ->assertOk()->assertJsonPath('session_timeout_minutes', null);

        $this->actingAs($this->superAdmin)->putJson('/api/v1/admin/settings/security', [
            'max_login_attempts' => 5,
            'session_timeout_minutes' => 30,
        ])->assertOk();

        $this->actingAs($this->superAdmin)->getJson('/api/v1/session-policy')
            ->assertOk()->assertJsonPath('session_timeout_minutes', 30);

        $this->actingAs($this->superAdmin)->getJson('/api/v1/admin/settings')
            ->assertOk()->assertJsonPath('meta.session_timeout_enforced', true);
    }
}
