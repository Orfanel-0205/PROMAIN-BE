<?php
// tests/Feature/Rhu/EventVisibilityTest.php
//
// Every resident sees every post; the RHU is its host.
//
// From 2026-09-20 a post could be restricted to one RHU's residents (rhu1,
// rhu2): it reached only residents whose barangay was "home" to that RHU. But
// every RHU serves the whole of Malasiqui, and on production every barangay's
// home was RHU 2, so an "RHU 1 only" post would have reached nobody. Since
// October 2026 posts reach every resident and are targeted by barangay; the
// RHU is shown as "Hosted by RHU 1". An older screen's rhu1/rhu2 is read as
// the host, and a facility opened today can host posts the same way.

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
    private User $superAdmin;
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
        $this->superAdmin = $this->makeUser('super_admin');
    }

    public function test_every_resident_sees_every_post_whichever_rhu_hosts_it(): void
    {
        $public = $this->publish('Free check-up for everyone');
        $rhu1 = $this->publish('RHU 1 immunization day', ['host_rhu_id' => 1]);
        $rhu2 = $this->publish('RHU 2 dental mission', ['host_rhu_id' => 2]);

        foreach ([$this->rhu1Resident, $this->rhu2Resident] as $resident) {
            $events = $this->eventsFor($resident);

            $this->assertArrayHasKey($public, $events);
            $this->assertArrayHasKey($rhu1, $events);
            $this->assertArrayHasKey($rhu2, $events, 'Every RHU serves the whole town.');
        }

        $events = $this->eventsFor($this->rhu1Resident);
        $this->assertSame('RHU 2', $events[$rhu2]['host_rhu_label']);
        $this->assertSame(2, $events[$rhu2]['host_rhu_id']);
    }

    public function test_an_older_screens_rhu_only_choice_becomes_the_host_and_the_post_reaches_everyone(): void
    {
        $title = $this->publish('Sent as RHU 2 only', ['visibility' => 'rhu2']);

        $this->assertDatabaseHas('events', ['title' => $title, 'host_rhu_id' => 2, 'visibility' => 'public']);
        $this->assertArrayHasKey($title, $this->eventsFor($this->rhu1Resident));
    }

    public function test_posts_written_before_the_field_existed_stay_public(): void
    {
        DB::table('events')->insert([
            'title' => 'Old post with no visibility set',
            'created_by' => $this->nurse->user_id,
            'is_published' => true,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertArrayHasKey('Old post with no visibility set', $this->eventsFor($this->rhu1Resident));
        $this->assertArrayHasKey('Old post with no visibility set', $this->eventsFor($this->rhu2Resident));
    }

    public function test_staff_still_see_every_post(): void
    {
        $rhu1 = $this->publish('RHU 1 immunization day', ['host_rhu_id' => 1]);
        $rhu2 = $this->publish('RHU 2 dental mission', ['host_rhu_id' => 2]);

        $events = $this->eventsFor($this->nurse);

        $this->assertArrayHasKey($rhu1, $events);
        $this->assertArrayHasKey($rhu2, $events);
    }

    public function test_a_newly_opened_rhu_can_host_posts_the_same_way(): void
    {
        $newId = (int) $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/rhus', [
                'code' => 'RHU3',
                'name' => 'RHU 3 Malasiqui',
                'short_name' => 'RHU 3',
                'latitude' => 15.9187,
                'longitude' => 120.4138,
            ])
            ->assertCreated()
            ->json('data.id');

        $title = $this->publish('RHU 3 opening day', ['host_rhu_id' => $newId]);

        // Seen by residents of any barangay, hosted by the new facility.
        $events = $this->eventsFor($this->rhu1Resident);
        $this->assertArrayHasKey($title, $events);
        $this->assertSame('RHU 3', $events[$title]['host_rhu_label']);
    }

    // ---------------------------------------------------------------- helpers

    /** Post through the dashboard's form, published. */
    private function publish(string $title, array $fields = []): string
    {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/admin/events', [
                'title' => $title,
                'description' => $title,
                'event_type' => 'event',
                'event_date' => now()->addDays(3)->toDateString(),
                'is_published' => true,
            ] + $fields)
            ->assertSuccessful();

        return $title;
    }

    /** @return array<string, array<string, mixed>> title => event */
    private function eventsFor(User $user): array
    {
        $response = $this->actingAs($user)->getJson('/api/v1/programs?per_page=100')->assertOk();
        $payload = $response->json('data') ?? $response->json();

        return collect(is_array($payload) ? $payload : [])
            ->filter(fn ($event) => !empty($event['title']))
            ->keyBy('title')
            ->all();
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
            'mobile_number' => sprintf('0917300%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }
}
