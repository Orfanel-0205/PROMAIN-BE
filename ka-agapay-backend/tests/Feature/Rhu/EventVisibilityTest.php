<?php
// tests/Feature/Rhu/EventVisibilityTest.php
//
// "RHU 1 residents only" has to mean something.
//
// Until 2026-09-20 the visibility field was stored, shown in the CMS form and
// validated — and never used in a single query. Every published post reached
// every resident regardless of the facility it was marked for. With a third
// RHU now possible, these tests hold the rule: residents see public posts and
// their own facility's posts, staff see everything, and a facility opened
// today can restrict posts the same way.

namespace Tests\Feature\Rhu;

use App\Models\Barangay;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Rhu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $rhu1Resident;
    private User $rhu2Resident;
    private User $nurse;
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        Rhu::flushCache();

        [$rhu1Barangay, $rhu2Barangay] = Barangay::orderBy('barangay_id')
            ->limit(2)
            ->pluck('barangay_id')
            ->all();

        DB::table('barangays')->where('barangay_id', $rhu1Barangay)->update(['rhu_id' => 1]);
        DB::table('barangays')->where('barangay_id', $rhu2Barangay)->update(['rhu_id' => 2]);

        $this->rhu1Resident = $this->makeResident($rhu1Barangay);
        $this->rhu2Resident = $this->makeResident($rhu2Barangay);
        $this->nurse = $this->makeUser('nurse');
    }

    public function test_a_resident_sees_public_posts_and_their_own_rhus_posts_only(): void
    {
        $public = $this->makeEvent('Free check-up for everyone', 'public');
        $rhu1Only = $this->makeEvent('RHU 1 immunization day', 'rhu1');
        $rhu2Only = $this->makeEvent('RHU 2 dental mission', 'rhu2');

        $titles = $this->titlesFor($this->rhu1Resident);

        $this->assertContains($public, $titles);
        $this->assertContains($rhu1Only, $titles);
        $this->assertNotContains($rhu2Only, $titles, 'An RHU 2 post must not reach an RHU 1 resident.');

        $titles = $this->titlesFor($this->rhu2Resident);

        $this->assertContains($public, $titles);
        $this->assertContains($rhu2Only, $titles);
        $this->assertNotContains($rhu1Only, $titles);
    }

    public function test_posts_written_before_the_field_existed_stay_public(): void
    {
        $legacy = $this->makeEvent('Old post with no visibility set', null);

        $this->assertContains($legacy, $this->titlesFor($this->rhu1Resident));
        $this->assertContains($legacy, $this->titlesFor($this->rhu2Resident));
    }

    public function test_staff_still_see_every_facilitys_posts(): void
    {
        $rhu1Only = $this->makeEvent('RHU 1 immunization day', 'rhu1');
        $rhu2Only = $this->makeEvent('RHU 2 dental mission', 'rhu2');

        $titles = $this->titlesFor($this->nurse);

        $this->assertContains($rhu1Only, $titles);
        $this->assertContains($rhu2Only, $titles);
    }

    public function test_a_newly_opened_rhu_can_restrict_posts_the_same_way(): void
    {
        $superAdmin = $this->makeUser('super_admin');

        $newId = (int) $this->actingAs($superAdmin)
            ->postJson('/api/v1/rhus', [
                'code' => 'RHU3',
                'name' => 'RHU 3 Malasiqui',
                'short_name' => 'RHU 3',
            ])
            ->assertCreated()
            ->json('data.id');

        $barangay = Barangay::orderBy('barangay_id')->skip(5)->first();

        $this->actingAs($superAdmin)
            ->putJson("/api/v1/rhus/{$newId}/barangays", ['barangay_ids' => [$barangay->barangay_id]])
            ->assertOk();

        $resident = $this->makeResident($barangay->barangay_id);
        $rhu3Only = $this->makeEvent('RHU 3 opening day', "rhu{$newId}");

        // Accepted by the CMS form's validation…
        $this->assertContains("rhu{$newId}", Rhu::visibilityValues());

        // …and it reaches that facility's residents and nobody else's.
        $this->assertContains($rhu3Only, $this->titlesFor($resident));
        $this->assertNotContains($rhu3Only, $this->titlesFor($this->rhu1Resident));
    }

    // ---------------------------------------------------------------- helpers

    /** @return string[] */
    private function titlesFor(User $user): array
    {
        $response = $this->actingAs($user)->getJson('/api/v1/programs')->assertOk();
        $payload = $response->json('data') ?? $response->json();

        return collect(is_array($payload) ? $payload : [])
            ->pluck('title')
            ->filter()
            ->values()
            ->all();
    }

    private function makeEvent(string $title, ?string $visibility): string
    {
        DB::table('events')->insert(array_filter([
            'title' => $title,
            'created_by' => $this->nurse->user_id,
            'visibility' => $visibility,
            'is_published' => true,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($value) => $value !== null));

        return $title;
    }

    private function makeResident(int $barangayId): User
    {
        $resident = $this->makeUser('resident');

        ResidentProfile::create([
            'user_id' => $resident->user_id,
            'barangay_id' => $barangayId,
            'birth_date' => '1991-01-01',
        ]);

        return $resident->fresh();
    }

    private function makeUser(string $role): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        return User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Tester' . ($this->phone + 1),
            'mobile_number' => sprintf('0917500%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }
}
