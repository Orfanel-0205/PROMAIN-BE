<?php
// tests/Feature/TeamChat/CallHandshakeTest.php
//
// A call handshake has to arrive exactly as it was sent.
//
// Every in-app call failed for days, on every network, including two browsers
// on one desk. The cause was not WebRTC and not the network: Laravel tidies
// the strings in an incoming request, which is right for a name typed into a
// form and wrong for a session description. An SDP is a protocol document
// whose every line, the last one included, must end in a carriage return and
// a newline. Trimming removed that final pair, the receiving browser could not
// parse what it was handed, and it never replied — while the interface blamed
// the two networks for not reaching each other.
//
// Nothing about that was visible from the outside, which is why it survived
// several rounds of looking. These tests hold the handshake byte for byte.

namespace Tests\Feature\TeamChat;

use App\Models\Conversation;
use App\Models\ConversationCall;
use App\Models\ConversationCallParticipant;
use App\Models\ConversationCallSignal;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallHandshakeTest extends TestCase
{
    use RefreshDatabase;

    private User $caller;
    private User $callee;
    private ConversationCall $call;
    private int $phone = 0;

    /** A real Chrome offer keeps every line CRLF-terminated, the last included. */
    private const SDP = "v=0\r\n"
        . "o=- 4611731400430051336 2 IN IP4 127.0.0.1\r\n"
        . "s=-\r\n"
        . "t=0 0\r\n"
        . "a=group:BUNDLE 0\r\n"
        . "m=audio 9 UDP/TLS/RTP/SAVPF 111\r\n"
        . "c=IN IP4 0.0.0.0\r\n"
        . "a=rtpmap:111 opus/48000/2\r\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);

        $this->caller = $this->makeStaff('nurse');
        $this->callee = $this->makeStaff('midwife');

        $convo = Conversation::create([
            'type' => 'dm',
            'created_by' => $this->caller->user_id,
            'dm_key' => $this->caller->user_id . '-' . $this->callee->user_id,
        ]);

        foreach ([$this->caller, $this->callee] as $member) {
            ConversationParticipant::create([
                'conversation_id' => $convo->id,
                'user_id' => $member->user_id,
            ]);
        }

        $this->call = ConversationCall::create([
            'conversation_id' => $convo->id,
            'started_by' => $this->caller->user_id,
            'room_name' => 'test-room',
            'mode' => 'audio',
            'started_at' => now(),
        ]);

        foreach ([$this->caller, $this->callee] as $member) {
            ConversationCallParticipant::create([
                'call_id' => $this->call->id,
                'user_id' => $member->user_id,
                'status' => 'joined',
                'joined_at' => now(),
            ]);
        }
    }

    public function test_an_offer_reaches_the_other_browser_exactly_as_it_was_sent(): void
    {
        $this->actingAs($this->caller, 'sanctum')
            ->postJson("/api/v1/team-chat/calls/{$this->call->id}/signal", [
                'type' => 'offer',
                'to_user_id' => $this->callee->user_id,
                'payload' => ['type' => 'offer', 'sdp' => self::SDP],
            ])
            ->assertCreated();

        $delivered = $this->actingAs($this->callee, 'sanctum')
            ->getJson("/api/v1/team-chat/calls/{$this->call->id}/signals")
            ->assertOk()
            ->json('data.0.payload.sdp');

        // The trailing CRLF is the whole point: without it the browser at the
        // other end cannot parse the offer and the call dies silently.
        $this->assertSame(self::SDP, $delivered);
        $this->assertStringEndsWith("\r\n", $delivered);
    }

    public function test_a_fresh_offer_clears_what_an_earlier_attempt_left_behind(): void
    {
        $stale = ConversationCallSignal::create([
            'call_id' => $this->call->id,
            'from_user_id' => $this->caller->user_id,
            'to_user_id' => $this->callee->user_id,
            'type' => 'offer',
            'payload' => ['type' => 'offer', 'sdp' => "v=0\r\nSTALE\r\n"],
        ]);

        $this->actingAs($this->caller, 'sanctum')
            ->postJson("/api/v1/team-chat/calls/{$this->call->id}/signal", [
                'type' => 'offer',
                'to_user_id' => $this->callee->user_id,
                'payload' => ['type' => 'offer', 'sdp' => self::SDP],
                'reset' => true,
            ])
            ->assertCreated();

        $signals = $this->actingAs($this->callee, 'sanctum')
            ->getJson("/api/v1/team-chat/calls/{$this->call->id}/signals")
            ->assertOk()
            ->json('data');

        // A call row is reused while it is still running, so without this the
        // second attempt answers the first attempt's dead offer and fails the
        // same way every time — which is why retrying never helped.
        $this->assertCount(1, $signals);
        $this->assertSame(self::SDP, $signals[0]['payload']['sdp']);
        $this->assertNotNull($stale->fresh()->consumed_at);
    }

    public function test_a_delivered_signal_is_not_handed_out_twice(): void
    {
        $this->actingAs($this->caller, 'sanctum')
            ->postJson("/api/v1/team-chat/calls/{$this->call->id}/signal", [
                'type' => 'ice',
                'to_user_id' => $this->callee->user_id,
                'payload' => ['candidates' => [['candidate' => 'candidate:1 1 udp 2 10.0.0.1 5 typ host']]],
            ])
            ->assertCreated();

        $first = $this->actingAs($this->callee, 'sanctum')
            ->getJson("/api/v1/team-chat/calls/{$this->call->id}/signals")
            ->json('data');

        $second = $this->actingAs($this->callee, 'sanctum')
            ->getJson("/api/v1/team-chat/calls/{$this->call->id}/signals")
            ->json('data');

        // Applying the same network route twice tears down a working link.
        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
    }

    public function test_a_signal_is_not_readable_by_someone_outside_the_call(): void
    {
        $stranger = $this->makeStaff('nurse');

        $this->actingAs($this->caller, 'sanctum')
            ->postJson("/api/v1/team-chat/calls/{$this->call->id}/signal", [
                'type' => 'offer',
                'to_user_id' => $this->callee->user_id,
                'payload' => ['type' => 'offer', 'sdp' => self::SDP],
            ])
            ->assertCreated();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/team-chat/calls/{$this->call->id}/signals")
            ->assertForbidden();
    }

    public function test_the_relay_password_expires_and_the_secret_never_leaves_the_server(): void
    {
        config([
            'services.turn.url' => 'turn:127.0.0.1:3478',
            'services.turn.secret' => 'a-shared-secret',
            'services.turn.ttl' => 3600,
        ]);

        $servers = $this->actingAs($this->callee, 'sanctum')
            ->postJson("/api/v1/team-chat/calls/{$this->call->id}/join")
            ->assertOk()
            ->json('data.peer.ice_servers');

        $relay = collect($servers)->firstWhere('urls', 'turn:127.0.0.1:3478');

        $this->assertNotNull($relay, 'the relay was not offered to the browser');

        // The username IS the expiry, and the password is that timestamp
        // signed with the secret. A credential copied out of a network tab
        // stops working on its own, and the secret is never sent anywhere.
        $this->assertGreaterThan(time(), (int) $relay['username']);
        $this->assertLessThanOrEqual(time() + 3600, (int) $relay['username']);
        $this->assertSame(
            base64_encode(hash_hmac('sha1', $relay['username'], 'a-shared-secret', true)),
            $relay['credential']
        );

        $this->assertStringNotContainsString('a-shared-secret', json_encode($servers));
    }

    public function test_without_a_relay_the_dashboard_is_told_so_plainly(): void
    {
        config(['services.turn.url' => '', 'services.turn.secret' => '']);

        $peer = $this->actingAs($this->callee, 'sanctum')
            ->postJson("/api/v1/team-chat/calls/{$this->call->id}/join")
            ->assertOk()
            ->json('data.peer');

        // Staff get an honest warning that a call between networks may not
        // connect, rather than a call that rings and reaches nothing.
        $this->assertFalse($peer['relay_configured']);
    }
    private function makeStaff(string $role): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        return User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Tester' . ($this->phone + 1),
            'mobile_number' => sprintf('0917600%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }
}
