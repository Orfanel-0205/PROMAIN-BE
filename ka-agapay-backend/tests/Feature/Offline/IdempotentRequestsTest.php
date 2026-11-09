<?php
// tests/Feature/Offline/IdempotentRequestsTest.php
//
// A save resent by the offline outbox is done once (Nov 2026).
//
// The admin and the app keep what is saved while the connection is down and
// send it when it returns; a reply lost on the way back makes them send it
// again. With the same Idempotency-Key the second send gets the first answer
// back and nothing is created twice. Without a key, nothing changes.

namespace Tests\Feature\Offline;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotentRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;
    private User $otherResident;
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->resident = $this->makeResident();
        $this->otherResident = $this->makeResident();
    }

    public function test_the_same_key_twice_saves_once_and_answers_the_same(): void
    {
        $first = $this->send($this->resident, 'outbox-1234-abcd', ['comment' => 'Mabilis ang serbisyo']);
        $second = $this->send($this->resident, 'outbox-1234-abcd', ['comment' => 'Mabilis ang serbisyo']);

        $first->assertSuccessful();
        $second->assertStatus($first->getStatusCode())->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, $this->feedbackCount($this->resident));
    }

    public function test_without_a_key_each_send_is_its_own(): void
    {
        $this->actingAs($this->resident)->postJson('/api/v1/feedback', ['comment' => 'Isa'])->assertSuccessful();
        $this->actingAs($this->resident)->postJson('/api/v1/feedback', ['comment' => 'Isa'])->assertSuccessful();

        $this->assertSame(2, $this->feedbackCount($this->resident));
    }

    public function test_a_key_reused_for_a_different_request_is_refused(): void
    {
        $this->send($this->resident, 'outbox-5678-efgh', ['comment' => 'Una'])->assertSuccessful();
        $this->send($this->resident, 'outbox-5678-efgh', ['comment' => 'Iba na'])->assertStatus(422);

        $this->assertSame(1, $this->feedbackCount($this->resident));
    }

    public function test_a_key_belongs_to_one_account(): void
    {
        $this->send($this->resident, 'outbox-shared-key', ['comment' => 'Akin'])->assertSuccessful();
        $this->send($this->otherResident, 'outbox-shared-key', ['comment' => 'Akin'])
            ->assertSuccessful()
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(1, $this->feedbackCount($this->resident));
        $this->assertSame(1, $this->feedbackCount($this->otherResident));
    }

    public function test_a_malformed_key_is_refused_before_anything_is_saved(): void
    {
        $this->send($this->resident, 'bad key!', ['comment' => 'X'])->assertStatus(400);

        $this->assertSame(0, $this->feedbackCount($this->resident));
    }

    private function send(User $user, string $key, array $body)
    {
        return $this->actingAs($user)->postJson('/api/v1/feedback', $body, ['Idempotency-Key' => $key]);
    }

    private function feedbackCount(User $user): int
    {
        return DB::table('service_feedback')->where('user_id', $user->user_id)->count();
    }

    private function makeResident(): User
    {
        $role = UserRole::firstOrCreate(['name' => 'resident'], ['permissions' => []]);

        return User::create([
            'role_id' => $role->role_id,
            'first_name' => 'Resident',
            'last_name' => 'Outbox' . ($this->phone + 1),
            'mobile_number' => sprintf('0917500%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }
}
