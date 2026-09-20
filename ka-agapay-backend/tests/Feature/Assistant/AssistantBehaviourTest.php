<?php
// tests/Feature/Assistant/AssistantBehaviourTest.php
//
// What the RHU assistant does when staff talk to it.
//
// The complaint these tests answer is "vague actions": asked to find someone,
// it used to reply with four generic steps ("use the search bar, usually found
// at the top") and do nothing. An assistant that is handover-ready has to
// either DO the thing, or say plainly that it cannot and open the screen that
// can.
//
// Everything asserted here is deterministic — no AI model is called — so these
// behaviours cannot drift when the model or its prompt changes. The cases with
// no deterministic answer still reach the model in production; they are not
// asserted here, only their shape.

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantBehaviourTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    /** Phrases that made staff call the assistant useless. */
    private const VAGUE_PHRASES = [
        'usually found',
        'search bar',
        'somewhere',
        'you may need to',
        'i think',
        'probably',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);

        $role = UserRole::firstOrCreate(['name' => 'nurse'], ['permissions' => []]);

        $this->nurse = User::create([
            'role_id' => $role->role_id,
            'first_name' => 'Nurse',
            'last_name' => 'Tester',
            'mobile_number' => '09176000001',
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }

    public function test_finding_a_person_opens_the_registry_with_the_name_filled_in(): void
    {
        $reply = $this->ask('look for Clifford');

        $this->assertSame('open_patient_registry', $reply->json('suggested_action'));
        $this->assertSame('Clifford', $reply->json('action_params.search'));
        $this->assertStringContainsString('Patient Registry', $reply->json('message.content'));
        $this->assertNotVague($reply->json('message.content'));
    }

    public function test_it_understands_the_same_request_in_tagalog_and_pangasinan(): void
    {
        foreach (['hanapin si Maria Santos', 'anapen si Maria Santos'] as $message) {
            $reply = $this->ask($message);

            $this->assertSame('open_patient_registry', $reply->json('suggested_action'), $message);
            $this->assertSame('Maria Santos', $reply->json('action_params.search'), $message);
        }
    }

    public function test_it_picks_the_screen_from_what_is_being_looked_for(): void
    {
        $this->assertSame(
            'open_prescriptions',
            $this->ask('find the prescription for Dela Cruz')->json('suggested_action')
        );

        $this->assertSame(
            'open_inventory',
            $this->ask('search medicine paracetamol')->json('suggested_action')
        );
    }

    public function test_asking_for_a_filtered_list_opens_it_already_filtered(): void
    {
        $reply = $this->ask('show pending appointments today');

        $this->assertSame('open_appointments', $reply->json('suggested_action'));
        $this->assertSame('pending', $reply->json('action_params.status'));
        $this->assertSame('today', $reply->json('action_params.date'));
        $this->assertNotVague($reply->json('message.content'));

        $reply = $this->ask('overdue follow-ups');

        $this->assertSame('open_followups', $reply->json('suggested_action'));
        $this->assertSame('overdue', $reply->json('action_params.status'));
    }

    public function test_a_question_about_numbers_admits_it_cannot_read_records(): void
    {
        $reply = $this->ask('how many patients are waiting in the queue?');
        $content = $reply->json('message.content');

        $this->assertSame('open_queue', $reply->json('suggested_action'));
        $this->assertStringContainsString('cannot read the records', $content);
        $this->assertStringContainsString('Queue', $content);
        $this->assertNotVague($content);
    }

    public function test_how_questions_still_get_an_explanation_not_a_search(): void
    {
        $reply = $this->ask('how do I approve an appointment?');

        // Not treated as "search for: I approve an appointment".
        $this->assertNull($reply->json('action_params.search'));
        $this->assertNotEmpty($reply->json('message.content'));
    }

    public function test_the_reply_comes_back_in_the_language_the_staff_member_chose(): void
    {
        $tagalog = $this->ask('hanapin si Maria', ['ui_language' => 'tag'])->json('message.content');
        $pangasinan = $this->ask('anapen si Maria', ['ui_language' => 'pag'])->json('message.content');

        $this->assertStringContainsString('Bubuksan ko po', $tagalog);
        $this->assertStringContainsString('Lukasan ko', $pangasinan);
    }

    public function test_every_answer_is_recorded_for_the_staff_member(): void
    {
        $reply = $this->ask('look for Clifford');

        $this->assertNotEmpty($reply->json('session_id'));
        $this->assertDatabaseHas('chat_messages', ['role' => 'user', 'message' => 'look for Clifford']);
    }

    // ---------------------------------------------------------------- helpers

    private function ask(string $message, array $context = [])
    {
        return $this->actingAs($this->nurse)
            ->postJson('/api/v1/chat/message', [
                'message' => $message,
                'audience' => 'staff',
                'source' => 'admin',
                'context' => array_merge([
                    'current_page' => '/dashboard',
                    'app_section' => 'rhu_admin_dashboard',
                ], $context),
            ])
            ->assertOk();
    }

    private function assertNotVague(?string $content): void
    {
        $lower = mb_strtolower((string) $content);

        foreach (self::VAGUE_PHRASES as $phrase) {
            $this->assertStringNotContainsString(
                $phrase,
                $lower,
                "The assistant should never fall back to vague wording like \"{$phrase}\"."
            );
        }
    }
}
