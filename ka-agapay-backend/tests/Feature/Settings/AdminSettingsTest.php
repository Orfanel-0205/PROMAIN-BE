<?php
// tests/Feature/Settings/AdminSettingsTest.php
//
// The admin Settings page used to write Facility Information, Notifications &
// SMS and Security Rules into ONE browser localStorage key
// ("ka_agapay_admin_settings_v2") and read them back from it. Nothing reached
// the server, so "saved" meant "saved in this browser, on this laptop, for
// this person" -- and a second admin, a second machine, or a cleared cache saw
// completely different settings.
//
// These tests pin the three properties that were missing:
//
//   1. values actually persist server-side and come back on a fresh read
//   2. an unconfigured install reports HONEST emptiness rather than the
//      plausible-looking defaults the old page shipped
//   3. Security Rules cannot be edited by roles that may only read settings
//
// Test 9 is the one that matters most: it proves Max Login Attempts is now
// genuinely enforced rather than merely stored, which was the whole complaint
// about the old panel.

namespace Tests\Feature\Settings;

use App\Models\AppSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Auth\BruteForceProtection;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $mho;
    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->userWithRole('super_admin', '09171100001');
        $this->mho        = $this->userWithRole('mho', '09171100002');
        $this->nurse      = $this->userWithRole('nurse', '09171100003');

        Cache::flush();
    }

    private function userWithRole(string $roleName, string $mobile, ?int $assignedRhuId = null): User
    {
        $role = UserRole::firstOrCreate(
            ['name' => $roleName],
            ['description' => ucfirst($roleName)],
        );

        return User::create([
            'role_id'         => $role->role_id,
            'first_name'      => ucfirst($roleName),
            'last_name'       => 'Tester',
            'mobile_number'   => $mobile,
            'password'        => bcrypt('password'),
            'account_status'  => 'active',
            'assigned_rhu_id' => $assignedRhuId,
        ]);
    }

    // ---------------------------------------------------------------- facility

    public function test_an_unconfigured_install_reports_empty_facility_fields_not_invented_ones(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/settings')
            ->assertOk();

        $facility = $response->json('facility');

        // Every one of these came back as a confident-looking string from the
        // old page on a browser where nobody had configured anything.
        $this->assertNull($facility['facility_name']);
        $this->assertNull($facility['address']);
        $this->assertNull($facility['contact_number']);
        $this->assertNull($facility['email']);
        $this->assertNull($facility['operating_hours']);

        $encoded = json_encode($response->json());
        $this->assertStringNotContainsString('RHU Malasiqui 1', $encoded);
        $this->assertStringNotContainsString('rhu@malasiqui.gov.ph', $encoded);
        $this->assertStringNotContainsString('XXX', $encoded);
    }

    public function test_facility_settings_round_trip_through_the_server(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings/facility', [
                'facility_name' => 'RHU 1 Malasiqui',
                'address' => 'Poblacion, Malasiqui, Pangasinan',
                'contact_number' => '+63 75 632 1234',
                'email' => 'rhu1@malasiqui.gov.ph',
                'operating_hours' => '8:00 AM - 5:00 PM',
            ])
            ->assertOk()
            ->assertJsonPath('facility.facility_name', 'RHU 1 Malasiqui');

        // A completely fresh read -- no in-memory state carried over.
        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('facility.contact_number', '+63 75 632 1234')
            ->assertJsonPath('facility.email', 'rhu1@malasiqui.gov.ph');

        $this->assertDatabaseHas('app_settings', [
            'group' => AppSetting::GROUP_FACILITY,
            'key' => 'facility_name',
            'value' => 'RHU 1 Malasiqui',
        ]);
    }

    public function test_facility_settings_are_stored_separately_per_rhu(): void
    {
        AppSettings::putSection(AppSetting::GROUP_FACILITY, 1, ['facility_name' => 'RHU 1 Malasiqui']);
        AppSettings::putSection(AppSetting::GROUP_FACILITY, 2, ['facility_name' => 'RHU 2 Malasiqui']);

        $this->assertSame('RHU 1 Malasiqui', AppSettings::section(AppSetting::GROUP_FACILITY, 1)['facility_name']);
        $this->assertSame('RHU 2 Malasiqui', AppSettings::section(AppSetting::GROUP_FACILITY, 2)['facility_name']);
    }

    public function test_the_placeholder_contact_number_the_old_page_shipped_is_rejected(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings/facility', [
                'facility_name' => 'RHU 1 Malasiqui',
                'address' => 'Poblacion, Malasiqui',
                'contact_number' => '+63 75 XXX XXXX', // the old default
                'operating_hours' => '8:00 AM - 5:00 PM',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_number');
    }

    public function test_facility_rejects_a_malformed_email(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings/facility', [
                'facility_name' => 'RHU 1 Malasiqui',
                'address' => 'Poblacion, Malasiqui',
                'contact_number' => '0756321234',
                'email' => 'not-an-email',
                'operating_hours' => '8:00 AM - 5:00 PM',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    // ----------------------------------------------------------- notifications

    public function test_notification_settings_round_trip_and_default_honestly(): void
    {
        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('notifications.appointment_reminder_hours', 24)
            ->assertJsonPath('notifications.queue_alert_ahead', 3)
            ->assertJsonPath('notifications.sms_provider', null);

        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings/notifications', [
                'sms_provider' => 'Semaphore PH',
                'appointment_reminder_hours' => 48,
                'queue_alert_ahead' => 5,
            ])
            ->assertOk();

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            // Still an int after the round-trip, not "48".
            ->assertJsonPath('notifications.appointment_reminder_hours', 48)
            ->assertJsonPath('notifications.queue_alert_ahead', 5);
    }

    public function test_notification_settings_reject_out_of_range_values(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings/notifications', [
                'appointment_reminder_hours' => 999,
                'queue_alert_ahead' => 3,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('appointment_reminder_hours');
    }

    // --------------------------------------------------------------- security

    public function test_security_settings_round_trip(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings/security', [
                'max_login_attempts' => 7,
                'session_timeout_minutes' => 45,
            ])
            ->assertOk();

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('security.max_login_attempts', 7)
            ->assertJsonPath('security.session_timeout_minutes', 45);
    }

    /**
     * The point of the whole exercise: this value is ENFORCED, not just stored.
     * Before, the panel offered 3-10 while the limiter used a hardcoded 5.
     */
    public function test_max_login_attempts_actually_changes_the_enforced_limit(): void
    {
        $this->assertSame(
            AppSettings::MAX_LOGIN_ATTEMPTS_FALLBACK,
            AppSettings::maxLoginAttempts(),
            'with nothing configured it must fall back to the previously hardcoded value',
        );

        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings/security', ['max_login_attempts' => 3])
            ->assertOk();

        $this->assertSame(3, AppSettings::maxLoginAttempts());
    }

    public function test_an_out_of_range_stored_value_is_clamped_rather_than_obeyed(): void
    {
        // Written past the API, as a corrupted row or a manual edit would be.
        AppSetting::create([
            'group' => AppSetting::GROUP_SECURITY,
            'rhu_id' => AppSetting::SHARED_RHU_ID,
            'key' => 'max_login_attempts',
            'value' => '9999',
            'type' => 'int',
        ]);
        AppSettings::forgetCache();

        $this->assertSame(AppSettings::MAX_LOGIN_ATTEMPTS_MAX, AppSettings::maxLoginAttempts());
    }

    /**
     * The assertion above proves the ACCESSOR returns the configured number.
     * It does not prove the rate limiter consumes it -- reverting the
     * RouteServiceProvider change would leave that test passing.
     *
     * These two drive the real /login endpoint instead, and they check both
     * directions, because a limiter that always blocks would satisfy a
     * lower-attempts test on its own.
     */
    public function test_a_lower_max_login_attempts_setting_blocks_sooner_on_the_real_endpoint(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings/security', ['max_login_attempts' => 3])
            ->assertOk();

        $attempt = fn () => $this->postJson('/api/v1/login', [
            'mobile_number' => '09179999001',
            'password' => 'definitely-wrong',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->assertNotSame(429, $attempt()->getStatusCode(), "attempt {$i} should be allowed through");
        }

        $attempt()->assertStatus(429);
    }

    public function test_a_higher_setting_permits_attempts_the_old_hardcoded_five_would_have_blocked(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings/security', ['max_login_attempts' => 10])
            ->assertOk();

        $attempt = fn () => $this->postJson('/api/v1/login', [
            'mobile_number' => '09179999002',
            'password' => 'definitely-wrong',
        ]);

        // Under the previous hardcoded Limit::perMinute(5) the sixth attempt
        // was a 429 no matter what the panel said.
        for ($i = 1; $i <= 6; $i++) {
            $this->assertNotSame(429, $attempt()->getStatusCode(), "attempt {$i} should be allowed at a limit of 10");
        }
    }

    /**
     * BruteForceProtection is the SECOND per-account guard (failures before a
     * 15-minute lockout) and it had its own hardcoded 5. Reverting it to that
     * left every other test in this file passing, so without this one the
     * change would have been shipped unverified.
     *
     * The assertion is deliberately shaped so the failure count never changes:
     * the same three recorded failures are "not locked" at a limit of 5 and
     * "locked" at a limit of 3, so only the setting can explain the flip.
     */
    public function test_the_brute_force_lockout_honours_the_configured_max_attempts(): void
    {
        $guard = new BruteForceProtection();
        $mobile = '09179999003';

        for ($i = 0; $i < 3; $i++) {
            $guard->recordFailedAttempt($mobile);
        }

        $this->assertFalse(
            $guard->isLocked($mobile),
            'three failures must not lock the account while the limit is the default 5',
        );

        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings/security', ['max_login_attempts' => 3])
            ->assertOk();

        $this->assertTrue(
            $guard->isLocked($mobile),
            'the same three failures must lock the account once the limit is 3',
        );
    }

    public function test_security_settings_reject_out_of_range_values(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/admin/settings/security', ['max_login_attempts' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_login_attempts');
    }

    // ------------------------------------------------------------ role gating

    public function test_an_mho_may_read_settings_but_not_edit_security_rules(): void
    {
        $this->actingAs($this->mho)
            ->getJson('/api/v1/admin/settings')
            ->assertOk();

        $this->actingAs($this->mho)
            ->putJson('/api/v1/admin/settings/security', ['max_login_attempts' => 3])
            ->assertStatus(403);
    }

    public function test_a_nurse_cannot_reach_settings_at_all(): void
    {
        $this->actingAs($this->nurse)
            ->getJson('/api/v1/admin/settings')
            ->assertStatus(403);

        $this->actingAs($this->nurse)
            ->putJson('/api/v1/admin/settings/facility', [
                'facility_name' => 'Hijacked',
                'address' => 'Somewhere',
                'contact_number' => '0756321234',
                'operating_hours' => '9-5',
            ])
            ->assertStatus(403);
    }

    public function test_settings_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/settings')->assertStatus(401);
    }

    // ------------------------------------------------------------- SMS + meta

    public function test_the_sms_key_indicator_reflects_the_server_not_a_hardcoded_true(): void
    {
        config(['services.semaphore.api_key' => '']);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            // The old UI defaulted this to TRUE, so an unconfigured install
            // displayed "Configured" for a key that did not exist.
            ->assertJsonPath('meta.sms_api_key_configured', false);

        config(['services.semaphore.api_key' => 'a-real-looking-key']);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('meta.sms_api_key_configured', true);
    }

    public function test_the_sms_api_key_value_is_never_returned(): void
    {
        config(['services.semaphore.api_key' => 'super-secret-key-value']);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/settings')
            ->assertOk();

        $this->assertStringNotContainsString(
            'super-secret-key-value',
            json_encode($response->json()),
            'the Semaphore API key must never appear in an API response',
        );
    }

    public function test_meta_states_plainly_which_values_are_enforced(): void
    {
        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('meta.max_login_attempts_enforced', true)
            ->assertJsonPath('meta.session_timeout_enforced', false)
            ->assertJsonPath('meta.sms_settings_enforced', false);
    }
}
