<?php
// tests/Feature/Auth/RegistrationInviteSmsTest.php
//
// A staff registration link can be texted to the number it is locked to, at
// the moment it is generated (16 Nov 2026): the token is never stored, so
// there is no later moment to send it. The SMS goes through SmsService, so it
// appears in the SMS Center log; a provider failure never loses the link.

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationInviteSmsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        config(['services.semaphore.api_key' => 'test-key', 'services.semaphore.base_url' => 'https://api.semaphore.co/api/v4']);

        $this->superAdmin = User::create([
            'role_id' => UserRole::where('name', 'super_admin')->value('role_id'),
            'first_name' => 'Super', 'last_name' => 'Admin', 'mobile_number' => '09170000099',
            'password' => bcrypt('password'), 'account_status' => 'active',
        ]);
    }

    public function test_the_link_is_texted_to_the_locked_number_when_asked(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response([['message_id' => 4242, 'recipient' => '09778090999', 'status' => 'Pending']])]);

        $response = $this->actingAs($this->superAdmin)->postJson('/api/v1/admin/registration-invites', [
            'intended_for' => 'Frances De Guzman - New MHO',
            'mobile_number' => '09778090999',
            'expires_in_minutes' => 10080,
            'send_sms' => true,
        ])->assertCreated();

        $url = $response->json('data.url');
        $this->assertStringContainsString('/register?', $url);
        $this->assertTrue($response->json('data.sms.sent'));
        $this->assertSame('09778090999', $response->json('data.sms.mobile'));

        Http::assertSent(function ($request) use ($url) {
            return str_contains($request->url(), '/messages')
                && $request['number'] === '09778090999'
                && str_contains($request['message'], $url)
                && str_contains($request['message'], 'Frances De Guzman');
        });

        $this->assertDatabaseHas('sms_logs', [
            'mobile_number' => '09778090999',
            'notification_type' => 'registration_invite',
            'status' => 'sent',
        ]);
        $this->assertSame(1, DB::table('registration_invites')->count());
    }

    public function test_a_failed_sms_still_returns_the_link(): void
    {
        Http::fake(['api.semaphore.co/*' => Http::response(['message' => 'Insufficient credits'], 402)]);

        $response = $this->actingAs($this->superAdmin)->postJson('/api/v1/admin/registration-invites', [
            'mobile_number' => '09778090999',
            'send_sms' => true,
        ])->assertCreated();

        $this->assertStringContainsString('/register?', $response->json('data.url'));
        $this->assertFalse($response->json('data.sms.sent'));
        $this->assertNotEmpty($response->json('data.sms.error'));
        $this->assertDatabaseHas('sms_logs', ['mobile_number' => '09778090999', 'notification_type' => 'registration_invite', 'status' => 'failed']);
    }

    public function test_sending_needs_a_number_and_is_off_by_default(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)->postJson('/api/v1/admin/registration-invites', [
            'intended_for' => 'No number',
            'send_sms' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['mobile_number']);

        $response = $this->actingAs($this->superAdmin)->postJson('/api/v1/admin/registration-invites', [
            'mobile_number' => '09778090999',
        ])->assertCreated();

        $this->assertNull($response->json('data.sms'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('sms_logs', 0);
    }
}
