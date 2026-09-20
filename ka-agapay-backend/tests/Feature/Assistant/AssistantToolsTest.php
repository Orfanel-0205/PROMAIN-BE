<?php
// tests/Feature/Assistant/AssistantToolsTest.php
//
// The assistant can now read Ka-Agapay's own numbers to answer questions.
// These tests hold the boundaries that make that safe:
//
//   - a staff member only ever gets their own RHU's figures;
//   - global-scope accounts (super admin, MHO) get every facility;
//   - the answers are counts and stock levels, never a patient's name,
//     because chat messages are stored and patient data must not land there;
//   - a facility opened today is asked about the same way as the originals.

namespace Tests\Feature\Assistant;

use App\Models\Barangay;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Ai\AssistantTools;
use App\Support\Rhu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssistantToolsTest extends TestCase
{
    use RefreshDatabase;

    private AssistantTools $tools;
    private User $nurseRhu1;
    private User $nurseRhu2;
    private User $superAdmin;
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        Rhu::flushCache();

        $this->tools = new AssistantTools();

        [$rhu1Barangay, $rhu2Barangay] = Barangay::whereNotIn('barangay_id', [1, 2])
            ->orderBy('barangay_id')
            ->limit(2)
            ->pluck('barangay_id')
            ->all();

        DB::table('barangays')->where('barangay_id', $rhu1Barangay)->update(['rhu_id' => 1]);
        DB::table('barangays')->where('barangay_id', $rhu2Barangay)->update(['rhu_id' => 2]);

        $this->nurseRhu1 = $this->makeUser('nurse', $rhu1Barangay);
        $this->nurseRhu2 = $this->makeUser('nurse', $rhu2Barangay);
        $this->superAdmin = $this->makeUser('super_admin');

        // Two queues, two facilities, deliberately different sizes.
        $this->issueTickets(1, 'waiting', 3);
        $this->issueTickets(1, 'completed', 2);
        $this->issueTickets(2, 'waiting', 7);

        // Stock: one item short at RHU 1, one healthy at RHU 2.
        $this->stockItem(1, 'Amoxicillin 500mg', 4, 20);
        $this->stockItem(2, 'Paracetamol 500mg', 500, 20);
    }

    public function test_staff_see_their_own_facilitys_queue_only(): void
    {
        $rhu1 = $this->tools->run('queue_status', [], $this->nurseRhu1);
        $rhu2 = $this->tools->run('queue_status', [], $this->nurseRhu2);

        $this->assertSame(3, $rhu1['waiting']);
        $this->assertSame(2, $rhu1['completed_today']);
        $this->assertSame('RHU 1', $rhu1['scope']);

        $this->assertSame(7, $rhu2['waiting']);
        $this->assertSame('RHU 2', $rhu2['scope']);
    }

    public function test_staff_cannot_ask_about_another_facility(): void
    {
        // Asking for RHU 2 explicitly still returns RHU 1's numbers.
        $result = $this->tools->run('queue_status', ['rhu_id' => 2], $this->nurseRhu1);

        $this->assertSame(3, $result['waiting']);
        $this->assertSame('RHU 1', $result['scope']);
    }

    public function test_a_super_admin_sees_every_facility_at_once(): void
    {
        $result = $this->tools->run('queue_status', [], $this->superAdmin);

        $this->assertSame(10, $result['waiting'], 'Both queues should be counted.');
        $this->assertSame('all RHUs', $result['scope']);
    }

    public function test_low_stock_is_reported_for_the_right_facility(): void
    {
        $rhu1 = $this->tools->run('low_stock_medicines', [], $this->nurseRhu1);
        $rhu2 = $this->tools->run('low_stock_medicines', [], $this->nurseRhu2);

        $this->assertSame(1, $rhu1['count']);
        $this->assertSame('Amoxicillin 500mg', $rhu1['items'][0]['name']);
        $this->assertSame(4, $rhu1['items'][0]['stock_left']);

        $this->assertSame(0, $rhu2['count'], 'A well-stocked facility reports nothing low.');
    }

    public function test_answers_carry_no_patient_details(): void
    {
        foreach (['queue_status', 'appointment_counts', 'prescription_counts', 'followup_counts'] as $tool) {
            $flat = mb_strtolower(json_encode($this->tools->run($tool, [], $this->nurseRhu1)));

            foreach (['name', 'mobile', 'diagnosis', 'birth', 'address'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $flat,
                    "{$tool} must return counts only — chat messages are stored, so patient details must never appear."
                );
            }
        }
    }

    public function test_a_newly_opened_facility_can_be_asked_about_too(): void
    {
        $newId = (int) $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/rhus', [
                'code' => 'RHU3',
                'name' => 'RHU 3 Malasiqui',
                'short_name' => 'RHU 3',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->issueTickets($newId, 'waiting', 5);

        $result = $this->tools->run('queue_status', ['rhu_id' => $newId], $this->superAdmin);

        $this->assertSame(5, $result['waiting']);
        $this->assertSame('RHU 3', $result['scope']);
    }

    public function test_every_tool_the_model_is_offered_actually_runs(): void
    {
        foreach ($this->tools->declarations() as $declaration) {
            $result = $this->tools->run($declaration['name'], [], $this->superAdmin);

            $this->assertIsArray($result);
            $this->assertArrayNotHasKey('error', $result, "{$declaration['name']} should not error on a normal call.");
            $this->assertArrayHasKey('as_of', $result);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function issueTickets(int $rhuId, string $status, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('queue_tickets')->insert([
                'ticket_number' => sprintf('RHU%d-OPD-%s-%04d', $rhuId, now()->format('Y'), ++$this->phone),
                'rhu_id' => $rhuId,
                'service_type' => 'consultation',
                'status' => $status,
                'issued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function stockItem(int $rhuId, string $name, int $stock, int $minimum): void
    {
        DB::table('inventory_items')->insert([
            'rhu_id' => $rhuId,
            'item_code' => 'MED-' . strtoupper(substr(md5($name . $rhuId), 0, 8)),
            'name' => $name,
            'category' => 'medicine',
            'unit_of_measure' => 'tablet',
            'current_stock' => $stock,
            'minimum_stock_level' => $minimum,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(string $role, ?int $assignedBarangayId = null): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        $attributes = [
            'role_id' => $roleRow->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Tester' . ($this->phone + 1),
            'mobile_number' => sprintf('0917700%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ];

        if ($assignedBarangayId !== null) {
            $attributes['assigned_rhu_id'] = $assignedBarangayId;
        }

        return User::create($attributes);
    }
}
