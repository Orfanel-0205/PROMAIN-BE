<?php
// tests/Feature/Notification/InventoryAlertTest.php
//
// A medicine running out, or about to expire, has to say so.
//
// The alert logic was complete and correct, and almost nothing called it. It
// ran on stock movements only, so a medicine entered with one box left, or
// given a nearer expiry date by an edit, raised nothing at all and waited for
// the next morning's sweep. Staff saw a red row on the Inventory screen and no
// alert anywhere else, which is the worst of both: it looks like the system
// noticed and decided not to mention it.
//
// These tests hold the two halves: the alert is raised at the moment an item
// enters a state worth alerting on, and the same problem is not reported over
// and over to people who have already been told.

namespace Tests\Feature\Notification;

use App\Models\InventoryItem;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\NotificationTypes;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryAlertTest extends TestCase
{
    use RefreshDatabase;

    private int $codes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);

        // Someone has to be there to be told.
        $role = UserRole::firstOrCreate(['name' => 'nurse'], ['permissions' => []]);

        User::create([
            'role_id' => $role->role_id,
            'first_name' => 'Nurse',
            'last_name' => 'Tester',
            'mobile_number' => '09177000001',
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }

    public function test_a_medicine_below_its_reorder_point_raises_an_alert(): void
    {
        $item = $this->makeItem(['current_stock' => 1, 'minimum_stock_level' => 20]);

        app(NotificationService::class)->notifyInventoryStockAlert($item);

        $this->assertSame(1, $this->alertCount('low_stock'));
    }

    public function test_a_medicine_close_to_expiry_raises_an_alert(): void
    {
        $item = $this->makeItem([
            'current_stock' => 500,
            'minimum_stock_level' => 20,
            'expiration_date' => now()->addDay()->toDateString(),
        ]);

        app(NotificationService::class)->notifyInventoryStockAlert($item);

        $this->assertSame(1, $this->alertCount('expiring'));
    }

    public function test_being_low_and_nearly_expired_reports_both(): void
    {
        // The case that started this: one box left, expiring tomorrow. They are
        // different problems needing different work -- use this one first, and
        // order more today -- so reporting only the worse of them loses one.
        $item = $this->makeItem([
            'current_stock' => 1,
            'minimum_stock_level' => 20,
            'expiration_date' => now()->addDay()->toDateString(),
        ]);

        app(NotificationService::class)->notifyInventoryStockAlert($item);

        $this->assertSame(1, $this->alertCount('low_stock'));
        $this->assertSame(1, $this->alertCount('expiring'));
    }

    public function test_a_healthy_medicine_says_nothing(): void
    {
        $item = $this->makeItem([
            'current_stock' => 500,
            'minimum_stock_level' => 20,
            'expiration_date' => now()->addYear()->toDateString(),
        ]);

        app(NotificationService::class)->notifyInventoryStockAlert($item);

        $this->assertSame(0, $this->alertCount('low_stock'));
        $this->assertSame(0, $this->alertCount('expiring'));
    }

    public function test_the_same_problem_is_not_reported_twice_in_one_day(): void
    {
        // The sweep runs every hour now. Without this, one low medicine would
        // produce twenty-four notifications a day and staff would stop reading
        // any of them -- which costs more than the missing alert did.
        $item = $this->makeItem(['current_stock' => 1, 'minimum_stock_level' => 20]);

        foreach (range(1, 5) as $ignored) {
            app(NotificationService::class)->notifyInventoryStockAlert($item);
        }

        $this->assertSame(1, $this->alertCount('low_stock'));
    }

    public function test_the_sweep_finds_items_nobody_has_touched(): void
    {
        // An item does not have to be handled to become a problem; it can just
        // sit there getting closer to its expiry date.
        $this->makeItem(['current_stock' => 2, 'minimum_stock_level' => 30]);
        $this->makeItem(['current_stock' => 900, 'minimum_stock_level' => 30]);

        $swept = app(NotificationService::class)->sweepInventoryAlerts();

        $this->assertSame(1, $swept);
        $this->assertSame(1, $this->alertCount('low_stock'));
    }

    /** How many alerts of this kind exist. */
    private function alertCount(string $kind): int
    {
        return DB::table('notifications')
            ->where('type', NotificationTypes::INVENTORY_LOW_STOCK)
            ->get()
            ->filter(function ($row) use ($kind) {
                $data = json_decode((string) $row->data, true);

                return ($data['alert_kind'] ?? null) === $kind;
            })
            // One notification per staff member; this is about how many
            // distinct problems were reported, not how many people were told.
            ->unique(fn ($row) => json_decode((string) $row->data, true)['dedupe_key'] ?? '')
            ->count();
    }

    private function makeItem(array $attributes): InventoryItem
    {
        return InventoryItem::create(array_merge([
            'rhu_id' => 1,
            'item_code' => sprintf('MED-TEST-%04d', ++$this->codes),
            'name' => 'Paracetamol',
            'category' => 'medicine',
            'unit_of_measure' => 'pcs',
            'current_stock' => 0,
            'minimum_stock_level' => 0,
            'is_active' => true,
        ], $attributes));
    }
}
