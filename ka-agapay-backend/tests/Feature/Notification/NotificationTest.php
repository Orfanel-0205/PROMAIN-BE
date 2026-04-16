<?php

namespace Tests\Feature\Notification;

use App\Models\User;
use App\Models\UserRole;
use App\Models\NotificationPreference;
use App\Notifications\NotificationTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Seed standard roles required for creating a User if they don't already exist
        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        $residentRole = UserRole::where('name', 'resident')->first();
        $this->user = User::create([
            'role_id'        => $residentRole->role_id,
            'first_name'     => 'Test',
            'last_name'      => 'User',
            'password'       => bcrypt('password'),
            'account_status' => 'active',
            'mobile_number'  => '09171234567',
        ]);
    }

    public function test_user_can_update_notification_preferences(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/notifications/preferences', [
                'preferences' => [
                    [
                        'notification_type' => NotificationTypes::QUEUE_TICKET_CALLED,
                        'in_app' => true,
                        'sms' => true,
                        'email' => false,
                    ]
                ]
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->user->user_id,
            'notification_type' => NotificationTypes::QUEUE_TICKET_CALLED,
            'sms' => true,
        ]);
    }

    public function test_user_can_retrieve_unread_count_when_notifications_exist(): void
    {
        // Give the user a simulated DB notification manually for testing
        $this->user->notifications()->create([
            'id' => \Illuminate\Support\Str::uuid(),
            'type' => 'App\Notifications\DummyNotification',
            'data' => ['title' => 'Test', 'body' => 'Test body'],
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/notifications/unread-count');

        $response->assertStatus(200)
            ->assertJson(['unread_count' => 1]);
    }

    public function test_user_can_mark_notifications_as_read(): void
    {
        $notification = $this->user->notifications()->create([
            'id' => \Illuminate\Support\Str::uuid(),
            'type' => 'App\Notifications\DummyNotification',
            'data' => ['title' => 'Test'],
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/notifications/read-all');

        $response->assertStatus(200);

        $this->assertNotNull($notification->fresh()->read_at);
    }
}
