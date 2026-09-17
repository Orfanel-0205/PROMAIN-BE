<?php
// tests/Feature/Prescription/DispensingAccountabilityTest.php
//
// Releasing and dispensing are about accountability: every hand-over must be
// traceable to a responsible staff member, name who received the medicine, and
// leave the stock count matching what actually left the drug room.
//
//   - only dispensing staff at the issuing RHU may release or dispense
//     (residents, BHWs and staff of the other RHU are refused)
//   - an onsite dispense records who handed it over, who received it, the
//     stock movement and an audit entry
//   - an online release (filled at an outside pharmacy) records who released it
//   - partial dispensing deducts only what was given, and the rest can follow
//   - the /dispense endpoint works (it used to fail on every call)

namespace Tests\Feature\Prescription;

use App\Models\Barangay;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DispensingAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $patient;
    private User $doctor;       // RHU 1, wrote the prescription
    private User $nurseRhu1;
    private User $nurseRhu2;
    private User $bhwRhu1;
    private int $rx;            // 20 x Paracetamol 500mg, issued by RHU 1
    private int $stockItem;     // RHU 1 drug room, 100 in stock
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private'); // generated prescription PDFs

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        // users.assigned_rhu_id is a foreign key to barangays: staff are placed
        // in an RHU through a barangay mapped to it (see SensitiveFileAccessTest).
        [$rhu1Barangay, $rhu2Barangay] = Barangay::whereNotIn('barangay_id', [1, 2])
            ->orderBy('barangay_id')
            ->limit(2)
            ->pluck('barangay_id')
            ->all();
        DB::table('barangays')->where('barangay_id', $rhu1Barangay)->update(['rhu_id' => 1]);
        DB::table('barangays')->where('barangay_id', $rhu2Barangay)->update(['rhu_id' => 2]);

        $this->patient = $this->makeUser('resident');
        $profile = ResidentProfile::create([
            'user_id'     => $this->patient->user_id,
            'barangay_id' => $rhu1Barangay,
            'birth_date'  => '1990-05-05',
        ]);

        $this->doctor = $this->makeUser('doctor', $rhu1Barangay);
        $this->nurseRhu1 = $this->makeUser('nurse', $rhu1Barangay);
        $this->nurseRhu2 = $this->makeUser('nurse', $rhu2Barangay);
        $this->bhwRhu1 = $this->makeUser('bhw', $rhu1Barangay);

        $this->stockItem = DB::table('inventory_items')->insertGetId([
            'rhu_id'              => 1,
            'item_code'           => 'MED-TEST-0001',
            'name'                => 'Paracetamol 500mg',
            'category'            => 'medicine',
            'unit_of_measure'     => 'tablet',
            'current_stock'       => 100,
            'minimum_stock_level' => 10,
            'is_active'           => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->rx = DB::table('prescriptions')->insertGetId([
            'resident_profile_id' => $profile->getKey(),
            'prescribed_by'       => $this->doctor->user_id,
            'prescription_number' => 'RHU1-RX-TEST-0001',
            'rhu_id'              => 1,
            'prescription_date'   => now()->toDateString(),
            'medications'         => json_encode([['name' => 'Paracetamol 500mg', 'quantity' => 20]]),
            'status'              => 'active',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    // ---------------------------------------------------------------- who may

    public function test_a_resident_cannot_dispense_even_their_own_prescription(): void
    {
        $this->actingAs($this->patient)
            ->postJson("/api/v1/prescriptions/{$this->rx}/release", [
                'dispense_from_rhu' => true,
                'received_by_name'  => 'Maria Reyes',
            ])
            ->assertForbidden();

        $this->actingAs($this->patient)
            ->postJson("/api/v1/prescriptions/{$this->rx}/dispense", ['received_by_name' => 'Maria Reyes'])
            ->assertForbidden();

        $this->assertUntouched();
    }

    public function test_staff_from_the_other_rhu_and_bhws_cannot_dispense(): void
    {
        // The other RHU's staff cannot even see this prescription, so they get
        // the same 404 as a missing one (ids cannot be probed).
        $this->actingAs($this->nurseRhu2)
            ->postJson("/api/v1/prescriptions/{$this->rx}/dispense", ['received_by_name' => 'Maria Reyes'])
            ->assertNotFound();

        // A BHW at the same RHU can see it but may not dispense it.
        $this->actingAs($this->bhwRhu1)
            ->postJson("/api/v1/prescriptions/{$this->rx}/dispense", ['received_by_name' => 'Maria Reyes'])
            ->assertForbidden();

        $this->assertUntouched();
    }

    // ---------------------------------------------------------------- what is recorded

    public function test_onsite_release_records_dispenser_receiver_stock_and_audit(): void
    {
        $this->actingAs($this->nurseRhu1)
            ->postJson("/api/v1/prescriptions/{$this->rx}/release", [
                'dispense_from_rhu'        => true,
                'received_by_name'         => 'Jose Reyes',
                'received_by_relationship' => 'Son',
            ])
            ->assertOk();

        $rx = DB::table('prescriptions')->find($this->rx);
        $this->assertSame('dispensed', $rx->status);
        $this->assertSame($this->nurseRhu1->user_id, (int) $rx->dispensed_by);
        $this->assertSame($this->nurseRhu1->user_id, (int) $rx->released_by);
        $this->assertNotNull($rx->released_at);

        $this->assertStock(80);

        $log = DB::table('prescription_dispensing_logs')->where('prescription_id', $this->rx)->sole();
        $this->assertSame($this->nurseRhu1->user_id, (int) $log->dispensed_by);
        $this->assertSame('Jose Reyes', $log->received_by_name);
        $this->assertSame('Son', $log->received_by_relationship);

        $this->assertTrue(
            DB::table('inventory_transactions')
                ->where('prescription_id', $this->rx)
                ->where('performed_by', $this->nurseRhu1->user_id)
                ->exists(),
            'The stock movement should name the nurse who dispensed.'
        );

        $this->assertAudited('prescription.dispensed', $this->nurseRhu1);
        $this->assertAudited('prescription.released', $this->nurseRhu1);
    }

    public function test_online_release_records_who_released_it_and_leaves_stock_alone(): void
    {
        $this->actingAs($this->nurseRhu1)
            ->postJson("/api/v1/prescriptions/{$this->rx}/release", ['dispense_from_rhu' => false])
            ->assertOk();

        $rx = DB::table('prescriptions')->find($this->rx);
        $this->assertSame('active', $rx->status);
        $this->assertSame($this->nurseRhu1->user_id, (int) $rx->released_by);
        $this->assertNotNull($rx->released_at);

        $this->assertStock(100);
        $this->assertAudited('prescription.released', $this->nurseRhu1);
    }

    public function test_every_dispense_names_who_received_the_medicine(): void
    {
        $this->actingAs($this->nurseRhu1)
            ->postJson("/api/v1/prescriptions/{$this->rx}/dispense", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('received_by_name');

        $this->actingAs($this->nurseRhu1)
            ->postJson("/api/v1/prescriptions/{$this->rx}/release", ['dispense_from_rhu' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('received_by_name');

        $this->assertUntouched();
    }

    public function test_recording_a_dispense_without_deducting_stock_needs_a_reason(): void
    {
        $this->actingAs($this->nurseRhu1)
            ->postJson("/api/v1/prescriptions/{$this->rx}/dispense", [
                'received_by_name' => 'Maria Reyes',
                'deduct_inventory' => false,
            ])
            ->assertStatus(422);

        $this->assertUntouched();
    }

    // ---------------------------------------------------------------- partial dispensing

    public function test_partial_dispense_deducts_only_what_was_given_and_the_rest_can_follow(): void
    {
        $this->actingAs($this->nurseRhu1)
            ->postJson("/api/v1/prescriptions/{$this->rx}/dispense", [
                'received_by_name' => 'Maria Reyes',
                'dispensed_items'  => [['name' => 'Paracetamol 500mg', 'quantity_dispensed' => 5]],
            ])
            ->assertOk();

        $this->assertSame('partially_dispensed', DB::table('prescriptions')->find($this->rx)->status);
        $this->assertStock(95);

        // The remaining 15, handed over later.
        $this->actingAs($this->nurseRhu1)
            ->postJson("/api/v1/prescriptions/{$this->rx}/dispense", ['received_by_name' => 'Maria Reyes'])
            ->assertOk();

        $this->assertSame('dispensed', DB::table('prescriptions')->find($this->rx)->status);
        $this->assertStock(80);
        $this->assertSame(2, DB::table('prescription_dispensing_logs')->where('prescription_id', $this->rx)->count());

        // Nothing left: a third dispense is refused and stock does not move.
        $this->actingAs($this->nurseRhu1)
            ->postJson("/api/v1/prescriptions/{$this->rx}/dispense", ['received_by_name' => 'Maria Reyes'])
            ->assertStatus(422);

        $this->assertStock(80);
    }

    public function test_cannot_hand_over_more_than_remains(): void
    {
        $this->actingAs($this->nurseRhu1)
            ->postJson("/api/v1/prescriptions/{$this->rx}/dispense", [
                'received_by_name' => 'Maria Reyes',
                'dispensed_items'  => [['name' => 'Paracetamol 500mg', 'quantity_dispensed' => 25]],
            ])
            ->assertStatus(422);

        $this->assertUntouched();
    }

    // ---------------------------------------------------------------- helpers

    private function assertUntouched(): void
    {
        $this->assertSame('active', DB::table('prescriptions')->find($this->rx)->status);
        $this->assertStock(100);
        $this->assertSame(0, DB::table('prescription_dispensing_logs')->where('prescription_id', $this->rx)->count());
    }

    private function assertStock(int $expected): void
    {
        $this->assertSame($expected, (int) DB::table('inventory_items')->where('id', $this->stockItem)->value('current_stock'));
    }

    private function assertAudited(string $event, User $actor): void
    {
        $this->assertTrue(
            DB::table('audit_logs')
                ->where('user_id', $actor->user_id)
                ->where(fn ($q) => $q->where('module', $event)->orWhere('action', $event))
                ->exists(),
            "Expected an audit entry '{$event}' by user {$actor->user_id}."
        );
    }

    private function makeUser(string $role, ?int $assignedBarangayId = null): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        $attributes = [
            'role_id'        => $roleRow->role_id,
            'first_name'     => ucfirst($role),
            'last_name'      => 'Tester' . ($this->phone + 1),
            'mobile_number'  => sprintf('0917300%04d', ++$this->phone),
            'password'       => bcrypt('password'),
            'account_status' => 'active',
        ];

        if ($assignedBarangayId !== null) {
            $attributes['assigned_rhu_id'] = $assignedBarangayId;
        }

        return User::create($attributes);
    }
}
