<?php
// tests/Feature/Queue/QueueListAccessTest.php
//
// Who may read the queue list (Oct 2026).
//
// GET /queue kept its own list of allowed roles, shorter than the queue
// policy's: no doctor, nurse or midwife. The live queue and its summary
// follow the policy, so a nurse ran the queue all day and was then refused
// the Queue report on the Reports page.

namespace Tests\Feature\Queue;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueListAccessTest extends TestCase
{
    use RefreshDatabase;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        // The list validates rhu_id against barangays.barangay_id (a legacy
        // link). Seeded ids run on between tests, so make sure this one is
        // there, as AttendanceReportTest does.
        $rhuId = \App\Support\Rhu::defaultId();
        if (!\Illuminate\Support\Facades\DB::table('barangays')->where('barangay_id', $rhuId)->exists()) {
            \Illuminate\Support\Facades\DB::table('barangays')->insert([
                'barangay_id' => $rhuId,
                'name' => 'Test Facility Barangay',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_clinical_staff_can_read_the_queue_list(): void
    {
        foreach (['nurse', 'midwife', 'doctor', 'rhu_admin', 'mho'] as $role) {
            $this->actingAs($this->user($role))
                ->getJson('/api/v1/queue?rhu_id=' . \App\Support\Rhu::defaultId())
                ->assertOk();
        }
    }

    public function test_a_resident_cannot_read_the_queue_list(): void
    {
        $this->actingAs($this->user('resident'))
            ->getJson('/api/v1/queue?rhu_id=' . \App\Support\Rhu::defaultId())
            ->assertForbidden();
    }

    private function user(string $role): User
    {
        return User::create([
            'role_id' => UserRole::firstOrCreate(['name' => $role], ['permissions' => []])->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Tester',
            'mobile_number' => sprintf('0918300%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
            'assigned_rhu_id' => $role === 'resident' ? null : 1,
        ]);
    }
}
