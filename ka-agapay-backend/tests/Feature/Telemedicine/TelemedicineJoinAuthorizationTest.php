<?php
// tests/Feature/Telemedicine/TelemedicineJoinAuthorizationTest.php
//
// Bug 1 — telemedicine join must require a JaaS JWT, and the JWT must only be
// mintable by an actual participant.
//
// Two halves, both necessary:
//
//   1. `join_url` carries the token, `room_url` does not. Clients must open
//      join_url. (The mobile app was opening room_url.)
//
//   2. Only a participant can obtain a session payload at all. Requiring a JWT
//      to join is meaningless if the endpoint that MINTS the JWT hands one to
//      any authenticated user — they would simply ask for their own valid token
//      to someone else's consultation.
//
// The keypair here is generated per-run and thrown away. The production PEM is
// never read by the test suite.

namespace Tests\Feature\Telemedicine;

use App\Models\Barangay;
use App\Models\ResidentProfile;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelemedicineJoinAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $patient;            // the resident the session is for
    private User $otherPatient;       // an unrelated resident — must be refused
    private User $doctor;             // the assigned doctor
    private User $nurse;              // screening staff — must KEEP access
    private TelemedicineSession $session;
    private bool $canSign = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        $barangay = Barangay::first();

        $this->configureThrowawayJaasTenant();

        $residentRole = UserRole::where('name', 'resident')->first();
        $doctorRole   = UserRole::where('name', 'doctor')->first()
            ?? UserRole::create(['name' => 'doctor', 'description' => 'Doctor']);

        $this->patient = User::create([
            'role_id'        => $residentRole->role_id,
            'first_name'     => 'Maria',
            'last_name'      => 'Reyes',
            'mobile_number'  => '09171000001',
            'password'       => bcrypt('password'),
            'account_status' => 'active',
        ]);

        $patientProfile = ResidentProfile::create([
            'user_id'     => $this->patient->user_id,
            'barangay_id' => $barangay->barangay_id,
            'birth_date'  => '1990-05-05',
        ]);

        $this->otherPatient = User::create([
            'role_id'        => $residentRole->role_id,
            'first_name'     => 'Unrelated',
            'last_name'      => 'Resident',
            'mobile_number'  => '09171000002',
            'password'       => bcrypt('password'),
            'account_status' => 'active',
        ]);

        ResidentProfile::create([
            'user_id'     => $this->otherPatient->user_id,
            'barangay_id' => $barangay->barangay_id,
            'birth_date'  => '1992-06-06',
        ]);

        $this->doctor = User::create([
            'role_id'        => $doctorRole->role_id,
            'first_name'     => 'Dr. Elena',
            'last_name'      => 'Cruz',
            'mobile_number'  => '09171000003',
            'password'       => bcrypt('password'),
            'account_status' => 'active',
        ]);

        $nurseRole = UserRole::where('name', 'nurse')->first()
            ?? UserRole::create(['name' => 'nurse', 'description' => 'Nurse']);

        $this->nurse = User::create([
            'role_id'        => $nurseRole->role_id,
            'first_name'     => 'Nurse',
            'last_name'      => 'Bautista',
            'mobile_number'  => '09171000004',
            'password'       => bcrypt('password'),
            'account_status' => 'active',
        ]);

        // NOTE: rhu_id references barangays(barangay_id) in the original
        // migration — a known legacy FK defect. Use a real barangay id here or
        // the insert fails on a clean database.
        $teleRequest = TelemedicineRequest::create([
            'resident_profile_id' => $patientProfile->id,
            'requested_by'        => $this->patient->user_id,
            'rhu_id'              => $barangay->barangay_id,
            'chief_complaint'     => 'Persistent cough for one week',
            'urgency_level'       => 'routine',
            'status'              => 'scheduled',
        ]);

        $this->session = TelemedicineSession::create([
            'request_id'         => $teleRequest->id,
            'assigned_doctor_id' => $this->doctor->user_id,
            'status'             => 'scheduled',
            'session_mode'       => 'video_call',
            'scheduled_date'     => now()->toDateString(),
            'scheduled_time'     => '10:00',
        ]);
    }

    /**
     * Point the JaaS config at a throwaway keypair so tokens really are signed
     * and can really be inspected, without touching production material.
     *
     * Key generation needs an openssl.cnf, which some PHP builds (notably the
     * Windows ones used for development here) do not locate on their own. When
     * no key can be produced, the AUTHORIZATION tests still run in full -- they
     * do not need signing -- and only the token-shape tests skip. That keeps the
     * security-critical half of this file meaningful on every machine.
     */
    private function configureThrowawayJaasTenant(): void
    {
        $pem = $this->generateThrowawayKey();

        config([
            'services.jitsi.provider'    => 'jaas',
            'services.jitsi.domain'      => '8x8.vc',
            'services.jitsi.jwt_enabled' => $pem !== null,
            'services.jitsi.app_id'      => 'vpaas-magic-cookie-testtenant',
            'services.jitsi.api_key'     => 'test-key-id',
            'services.jitsi.private_key' => $pem,
        ]);

        $this->canSign = $pem !== null;
    }

    private function generateThrowawayKey(): ?string
    {
        $candidates = array_filter([
            null,                                        // whatever PHP finds by itself
            getenv('OPENSSL_CONF') ?: null,
            'C:/Program Files/Git/usr/ssl/openssl.cnf',  // Git for Windows
            '/usr/lib/ssl/openssl.cnf',                  // Debian / Ubuntu
            '/etc/ssl/openssl.cnf',
        ], fn ($path) => $path === null || is_file($path));

        foreach ($candidates as $config) {
            $args = [
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ];

            if ($config !== null) {
                $args['config'] = $config;
            }

            $key = @openssl_pkey_new($args);

            if ($key && @openssl_pkey_export($key, $pem, null, $args)) {
                return $pem;
            }
        }

        // Drain the error queue so a later, unrelated openssl call is not
        // confused by leftovers from these attempts.
        while (openssl_error_string() !== false) {
            // discard
        }

        return null;
    }

    private function requireSigningKey(): void
    {
        if (!$this->canSign) {
            $this->markTestSkipped(
                'No usable openssl.cnf on this machine, so no throwaway RS256 key could be '
                . 'generated. Token-shape assertions are skipped; the authorization tests in '
                . 'this file still ran.'
            );
        }
    }

    // ---------------------------------------------------------------------
    // Half 1 — the JWT must be attached to the URL clients are told to open
    // ---------------------------------------------------------------------

    public function test_join_url_carries_the_jwt_and_room_url_stays_token_free(): void
    {
        $this->requireSigningKey();

        $response = $this->actingAs($this->patient)
            ->getJson('/api/v1/telemedicine/sessions/' . $this->session->id);

        $response->assertOk();

        $video = $response->json('data.video');

        $this->assertNotEmpty($video['jwt'] ?? null, 'A participant must be issued a JWT.');

        $this->assertStringContainsString(
            'jwt=',
            $video['join_url'],
            'join_url is the URL clients open — it must carry the token.'
        );

        $this->assertStringNotContainsString(
            'jwt=',
            $video['room_url'],
            'room_url is the safe-to-log form and must stay token-free.'
        );
    }

    public function test_jwt_precedes_the_url_fragment_so_the_server_receives_it(): void
    {
        $this->requireSigningKey();

        $video = $this->actingAs($this->patient)
            ->getJson('/api/v1/telemedicine/sessions/' . $this->session->id)
            ->json('data.video');

        $joinUrl  = $video['join_url'];
        $jwtAt    = strpos($joinUrl, 'jwt=');
        $hashAt   = strpos($joinUrl, '#');

        $this->assertNotFalse($jwtAt);

        // A token placed after '#' lives in the fragment and is never sent to
        // the server, so the tenant rejects the join. This is a regression guard
        // for exactly that defect.
        if ($hashAt !== false) {
            $this->assertLessThan(
                $hashAt,
                $jwtAt,
                'The jwt query parameter must appear BEFORE the # fragment.'
            );
        }
    }

    public function test_the_token_names_the_joiner_not_always_the_doctor(): void
    {
        $this->requireSigningKey();

        $patientJwt = $this->actingAs($this->patient)
            ->getJson('/api/v1/telemedicine/sessions/' . $this->session->id)
            ->json('data.video.jwt');

        $doctorJwt = $this->actingAs($this->doctor)
            ->getJson('/api/v1/telemedicine/sessions/' . $this->session->id)
            ->json('data.video.jwt');

        $this->assertNotSame($patientJwt, $doctorJwt, 'Each participant gets their own token.');

        $patientName = $this->decodeJwtPayload($patientJwt)['context']['user']['name'] ?? null;
        $doctorName  = $this->decodeJwtPayload($doctorJwt)['context']['user']['name'] ?? null;

        $this->assertStringContainsString('Maria', (string) $patientName);
        $this->assertStringContainsString('Elena', (string) $doctorName);
    }

    /**
     * Regression guard for the "Key ID (kid) does not match sub" rejection.
     *
     * JaaS requires the kid header to be "<AppID>/<shortKeyId>" and refuses a
     * bare short id. keyId() used to return config('services.jitsi.api_key')
     * untouched, so production signed every token with kid="0aa1cb" while sub
     * was the full AppID -- well-formed, correctly signed, and rejected by 8x8.
     * A single assertion on the header would have caught it.
     */
    public function test_the_kid_header_is_prefixed_with_the_app_id(): void
    {
        $this->requireSigningKey();

        $jwt = $this->actingAs($this->patient)
            ->getJson('/api/v1/telemedicine/sessions/' . $this->session->id)
            ->json('data.video.jwt');

        $header = $this->decodeJwtSegment(explode('.', $jwt)[0]);
        $claims = $this->decodeJwtPayload($jwt);

        $appId = (string) config('services.jitsi.app_id');

        $this->assertSame(
            $appId . '/' . 'test-key-id',
            $header['kid'] ?? null,
            'kid must be "<AppID>/<shortKeyId>", not the bare key id.'
        );

        // This is the exact relationship 8x8 checks when it reports
        // "Key ID (kid) does not match sub".
        $this->assertStringStartsWith(
            $claims['sub'] . '/',
            $header['kid'],
            'kid must be prefixed with the sub claim.'
        );
    }

    public function test_both_participants_are_issued_tokens_for_the_same_room(): void
    {
        $patientVideo = $this->actingAs($this->patient)
            ->getJson('/api/v1/telemedicine/sessions/' . $this->session->id)
            ->json('data.video');

        $doctorVideo = $this->actingAs($this->doctor)
            ->getJson('/api/v1/telemedicine/sessions/' . $this->session->id)
            ->json('data.video');

        $this->assertSame(
            $patientVideo['room_name'],
            $doctorVideo['room_name'],
            'Patient and doctor must land in the SAME room.'
        );
    }

    // ---------------------------------------------------------------------
    // Half 2 — only a participant may obtain a token at all
    // ---------------------------------------------------------------------

    public function test_unrelated_resident_cannot_obtain_a_session_or_its_token(): void
    {
        $response = $this->actingAs($this->otherPatient)
            ->getJson('/api/v1/telemedicine/sessions/' . $this->session->id);

        $response->assertForbidden();

        // Belt and braces: even if the status ever regresses, no token and no
        // clinical detail may appear in the body.
        $body = $response->getContent();
        $this->assertStringNotContainsString('jwt', strtolower($body));
        $this->assertStringNotContainsString('Persistent cough', $body);
    }

    public function test_guest_cannot_obtain_a_session(): void
    {
        $this->getJson('/api/v1/telemedicine/sessions/' . $this->session->id)
            ->assertUnauthorized();
    }

    public function test_participant_resident_can_still_view_their_own_session(): void
    {
        $this->actingAs($this->patient)
            ->getJson('/api/v1/telemedicine/sessions/' . $this->session->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->session->id);
    }

    public function test_assigned_doctor_can_still_view_the_session(): void
    {
        $this->actingAs($this->doctor)
            ->getJson('/api/v1/telemedicine/sessions/' . $this->session->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->session->id);
    }

    public function test_session_list_only_returns_the_callers_own_sessions(): void
    {
        $this->actingAs($this->otherPatient)
            ->getJson('/api/v1/telemedicine/sessions')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->patient)
            ->getJson('/api/v1/telemedicine/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * Regression guard for the workflow this fix could have broken.
     *
     * The web admin's "Start Video" / "Open Room" controls are gated by session
     * status, not by role, so screening staff can open a room today. Authorizing
     * the endpoint without accounting for that would have 403'd them mid-shift.
     *
     * If the RHU later decides only the assigned clinician may enter a
     * consultation, this is the test that should change — deliberately, with the
     * frontend gating updated in the same pass.
     */
    public function test_screening_nurse_retains_access_to_the_session(): void
    {
        $this->actingAs($this->nurse)
            ->getJson('/api/v1/telemedicine/sessions/' . $this->session->id)
            ->assertOk();
    }

    // ---------------------------------------------------------------------

    private function decodeJwtSegment(string $segment): array
    {
        return json_decode(base64_decode(strtr($segment, '-_', '+/')), true) ?: [];
    }

    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'Not a well-formed JWT.');

        $payload = base64_decode(strtr($parts[1], '-_', '+/'));

        return json_decode($payload, true) ?: [];
    }
}
