<?php
// tests/Feature/Queue/QueuePriorityAgeTest.php
//
// Ages and waiting times in the queue's priority score (Oct 2026).
//
// Carbon 3, which Laravel 11+ uses, returns SIGNED differences:
// now()->diffInYears(a birth date) is negative. The priority service measured
// that way, so every senior had a negative age and never got the senior
// weight, and every waiting ticket had negative minutes and never got the
// long-wait (anti-starvation) bonus.

namespace Tests\Feature\Queue;

use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Queue\QueuePrioritizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueuePriorityAgeTest extends TestCase
{
    use RefreshDatabase;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);
    }

    public function test_a_senior_scores_above_a_younger_adult_with_the_same_visit(): void
    {
        $service = app(QueuePrioritizationService::class);

        $senior = $service->computePriorityScore($this->resident(now()->subYears(72)->toDateString()));
        $adult = $service->computePriorityScore($this->resident(now()->subYears(30)->toDateString()));

        $this->assertGreaterThan($adult['priority_score'], $senior['priority_score']);
    }

    public function test_a_long_wait_raises_a_tickets_score_and_a_short_one_does_not(): void
    {
        $long = $this->ticket(now()->subMinutes(70));
        $short = $this->ticket(now()->subMinutes(5));

        app(QueuePrioritizationService::class)->applyAntiStarvation(1);

        $this->assertGreaterThan(10, $long->fresh()->priority_score);
        $this->assertSame(10, (int) $short->fresh()->priority_score);
    }

    public function test_the_average_wait_is_a_positive_number_of_minutes(): void
    {
        $this->ticket(now()->subMinutes(40));
        $this->ticket(now()->subMinutes(20));

        $stats = app(QueuePrioritizationService::class)->getQueueCongestion(1);

        $this->assertEqualsWithDelta(30, $stats['avg_wait_minutes'], 1);
    }

    private function ticket($issuedAt): QueueTicket
    {
        static $n = 0;

        return QueueTicket::create([
            'ticket_number' => sprintf('OPD-%03d', ++$n),
            'resident_profile_id' => $this->resident('1990-01-01')->id,
            'rhu_id' => 1,
            'service_type' => 'opd_consultation',
            'priority_score' => 10,
            'priority_category' => 'regular',
            'status' => 'waiting',
            'issued_at' => $issuedAt,
        ]);
    }

    private function resident(string $birthDate): ResidentProfile
    {
        $role = UserRole::firstOrCreate(['name' => 'resident'], ['permissions' => []]);

        $user = User::create([
            'role_id' => $role->role_id,
            'first_name' => 'Resident',
            'last_name' => 'Tester' . ($this->phone + 1),
            'mobile_number' => sprintf('0918100%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);

        return ResidentProfile::create([
            'user_id' => $user->user_id,
            'barangay_id' => \App\Models\Barangay::first()->barangay_id,
            'birth_date' => $birthDate,
        ]);
    }
}
