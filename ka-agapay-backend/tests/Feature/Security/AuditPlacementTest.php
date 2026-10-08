<?php
// tests/Feature/Security/AuditPlacementTest.php
//
// The audit log files each entry under its module ("rhu") with its action
// ("rhu.created"). Twenty calls had the two the other way round, so filtering
// the log by module missed their entries; the 2026-10-08 migration swapped the
// rows already written back into place.

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditPlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_entry_is_filed_under_its_module(): void
    {
        $this->seed(\Database\Seeders\UserRoleSeeder::class);

        $superAdmin = User::create([
            'role_id' => UserRole::firstOrCreate(['name' => 'super_admin'], ['permissions' => []])->role_id,
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'mobile_number' => '09171110001',
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);

        $this->actingAs($superAdmin)->postJson('/api/v1/rhus', [
            'code' => 'RHU3',
            'name' => 'RHU 3 Malasiqui',
            'short_name' => 'RHU 3',
            'latitude' => 15.9187,
            'longitude' => 120.4138,
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['module' => 'rhu', 'action' => 'rhu.created']);
        $this->assertDatabaseMissing('audit_logs', ['module' => 'rhu.created']);
    }

    public function test_the_migration_puts_swapped_rows_back_and_leaves_correct_ones_alone(): void
    {
        $swapped = DB::table('audit_logs')->insertGetId([
            'module' => 'telemedicine_notes.finalized', 'action' => 'telemedicine',
            'severity' => 'info', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $correct = DB::table('audit_logs')->insertGetId([
            'module' => 'events', 'action' => 'event.updated',
            'severity' => 'info', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = include database_path('migrations/2026_10_08_010000_fix_swapped_audit_log_columns.php');
        $migration->up();
        $migration->up(); // twice changes nothing more

        $this->assertSame(
            ['telemedicine', 'telemedicine_notes.finalized'],
            array_values((array) DB::table('audit_logs')->where('id', $swapped)->first(['module', 'action']))
        );
        $this->assertSame(
            ['events', 'event.updated'],
            array_values((array) DB::table('audit_logs')->where('id', $correct)->first(['module', 'action']))
        );
    }
}
