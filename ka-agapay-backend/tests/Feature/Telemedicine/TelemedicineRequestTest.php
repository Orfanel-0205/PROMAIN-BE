<?php
// tests/Feature/Telemedicine/TelemedicineRequestTest.php

namespace Tests\Feature\Telemedicine;

use App\Models\Barangay;
use App\Models\ResidentProfile;
use App\Models\TelemedicineRequest;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelemedicineRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $residentUser;
    private User $screeningUser;
    private User $mhoUser;
    private ResidentProfile $residentProfile;
    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        $this->barangay = Barangay::first();

        // Setup Resident
        $residentRole = UserRole::where('name', 'resident')->first();

        $this->residentUser = User::create([
            'role_id'        => $residentRole->role_id,
            'first_name'     => 'Jane',
            'last_name'      => 'Doe',
            'mobile_number'  => '09181234567',
            'password'       => bcrypt('password'),
            'account_status' => 'active',
        ]);

        $this->residentProfile = ResidentProfile::create([
            'user_id'     => $this->residentUser->user_id,
            'barangay_id' => $this->barangay->barangay_id,
            'birth_date'  => '1995-01-01',
        ]);

        // Level 1 screening role: nurse / midwife / head_nurse / staff / rhu_staff / rhu_admin
        $nurseRole = UserRole::where('name', 'nurse')->first();

        if (!$nurseRole) {
            $nurseRole = UserRole::create([
                'name'        => 'nurse',
                'description' => 'Nurse',
            ]);
        }

        $this->screeningUser = User::create([
            'role_id'        => $nurseRole->role_id,
            'first_name'     => 'Nurse',
            'last_name'      => 'Santos',
            'mobile_number'  => '09998887777',
            'password'       => bcrypt('password'),
            'account_status' => 'active',
        ]);

        // Level 2 clinical role: Doctor / MHO
        $mhoRole = UserRole::where('name', 'mho')->first();

        if (!$mhoRole) {
            $mhoRole = UserRole::create([
                'name'        => 'mho',
                'description' => 'MHO',
            ]);
        }

        $this->mhoUser = User::create([
            'role_id'        => $mhoRole->role_id,
            'first_name'     => 'Dr. Smith',
            'last_name'      => 'Will',
            'mobile_number'  => '09998887778',
            'password'       => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }

    public function test_resident_can_create_telemedicine_request(): void
    {
        $payload = [
            'resident_profile_id' => $this->residentProfile->id,
            'rhu_id'              => $this->barangay->barangay_id,
            'chief_complaint'     => 'Severe headache for 3 days',
            'urgency_level'       => 'urgent',
            'symptoms'            => ['Headache', 'Fever'],
        ];

        $response = $this->actingAs($this->residentUser)
            ->postJson('/api/v1/telemedicine/requests', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.chief_complaint', 'Severe headache for 3 days');

        $this->assertDatabaseHas('telemedicine_requests', [
            'resident_profile_id' => $this->residentProfile->id,
            'chief_complaint'     => 'Severe headache for 3 days',
            'status'              => 'pending',
        ]);
    }

    public function test_nurse_can_screen_telemedicine_request(): void
    {
        $teleRequest = TelemedicineRequest::create([
            'resident_profile_id' => $this->residentProfile->id,
            'requested_by'        => $this->residentUser->user_id,
            'rhu_id'              => $this->barangay->barangay_id,
            'chief_complaint'     => 'Severe headache for 3 days',
            'urgency_level'       => 'urgent',
            'symptoms'            => ['Headache', 'Fever'],
            'status'              => 'pending',
        ]);

        $response = $this->actingAs($this->screeningUser)
            ->patchJson("/api/v1/telemedicine/requests/{$teleRequest->id}/screen", [
                'decision'      => 'approve',
                'urgency_level' => 'urgent',
                'notes'         => 'Approved for teleconsultation.',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('telemedicine_requests', [
            'id'          => $teleRequest->id,
            'status'      => 'screened',
            'screened_by' => $this->screeningUser->user_id,
        ]);
    }

    public function test_mho_cannot_screen_telemedicine_request(): void
    {
        $teleRequest = TelemedicineRequest::create([
            'resident_profile_id' => $this->residentProfile->id,
            'requested_by'        => $this->residentUser->user_id,
            'rhu_id'              => $this->barangay->barangay_id,
            'chief_complaint'     => 'Severe headache for 3 days',
            'urgency_level'       => 'urgent',
            'symptoms'            => ['Headache', 'Fever'],
            'status'              => 'pending',
        ]);

        $response = $this->actingAs($this->mhoUser)
            ->patchJson("/api/v1/telemedicine/requests/{$teleRequest->id}/screen", [
                'decision'      => 'approve',
                'urgency_level' => 'urgent',
                'notes'         => 'MHO should not perform Level 1 screening.',
            ]);

        $response->assertStatus(403);
    }

    public function test_resident_can_view_own_requests(): void
    {
        TelemedicineRequest::create([
            'resident_profile_id' => $this->residentProfile->id,
            'requested_by'        => $this->residentUser->user_id,
            'rhu_id'              => $this->barangay->barangay_id,
            'chief_complaint'     => 'Back pain',
            'urgency_level'       => 'routine',
            'status'              => 'pending',
        ]);

        $response = $this->actingAs($this->residentUser)
            ->getJson('/api/v1/telemedicine/requests/mine');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}