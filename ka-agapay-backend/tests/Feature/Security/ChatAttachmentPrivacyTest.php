<?php
// tests/Feature/Security/ChatAttachmentPrivacyTest.php
//
// A photograph sent in Team Chat is not a public file.
//
// Attachments were written to the 'public' disk and served at /storage/...,
// which needs no login and is guessable. That is the same fault that was found
// and closed for resident ID photographs and prescription PDFs; Team Chat was
// missed, and what staff send each other through it is wound photographs,
// laboratory results and photographed referral papers.
//
// Being staff is not enough to read one. The rule that already governs the
// messages themselves -- you must be in that conversation -- now governs their
// attachments too, because a photograph of a patient's leg is not less private
// than the sentence sent beside it.

namespace Tests\Feature\Security;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Models\UserRole;
use App\Support\SensitiveFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatAttachmentPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;
    private User $midwife;
    private User $stranger;
    private Conversation $convo;
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);

        $this->nurse = $this->makeStaff('nurse');
        $this->midwife = $this->makeStaff('midwife');
        $this->stranger = $this->makeStaff('doctor');

        $this->convo = Conversation::create([
            'type' => 'dm',
            'created_by' => $this->nurse->user_id,
            'dm_key' => $this->nurse->user_id . '-' . $this->midwife->user_id,
        ]);

        foreach ([$this->nurse, $this->midwife] as $member) {
            ConversationParticipant::create([
                'conversation_id' => $this->convo->id,
                'user_id' => $member->user_id,
            ]);
        }
    }

    public function test_an_uploaded_attachment_is_not_written_to_the_public_disk(): void
    {
        Storage::fake('private');
        Storage::fake('public');

        $path = $this->actingAs($this->nurse, 'sanctum')
            ->postJson('/api/v1/team-chat/attachments', [
                'image' => UploadedFile::fake()->image('wound.jpg'),
            ])
            ->assertCreated()
            ->json('data.attachment_path');

        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_no_direct_link_is_handed_back_for_a_private_attachment(): void
    {
        Storage::fake('private');

        // A URL in the response is what made this shareable in the first place.
        // The sender previews the picture already on their own machine.
        $this->actingAs($this->nurse, 'sanctum')
            ->postJson('/api/v1/team-chat/attachments', [
                'image' => UploadedFile::fake()->image('wound.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.url', null);
    }

    public function test_a_group_avatar_stays_public_because_it_is_decoration(): void
    {
        Storage::fake('public');

        $this->actingAs($this->nurse, 'sanctum')
            ->postJson('/api/v1/team-chat/attachments', [
                'image' => UploadedFile::fake()->image('group.png'),
                'purpose' => 'group_image',
            ])
            ->assertCreated()
            ->assertJsonPath('data.url', fn ($url) => is_string($url) && $url !== '');
    }

    public function test_the_other_person_in_the_conversation_can_open_it(): void
    {
        $message = $this->makeMessageWithAttachment();

        $this->actingAs($this->midwife, 'sanctum')
            ->get("/api/v1/team-chat/messages/{$message->id}/attachment")
            ->assertOk();
    }

    public function test_staff_outside_the_conversation_cannot_open_it(): void
    {
        // The whole point. A doctor is staff, is logged in, and still has no
        // business reading a photograph sent between two other people.
        $message = $this->makeMessageWithAttachment();

        $this->actingAs($this->stranger, 'sanctum')
            ->get("/api/v1/team-chat/messages/{$message->id}/attachment")
            ->assertForbidden();
    }

    public function test_a_signed_out_visitor_cannot_open_it(): void
    {
        $message = $this->makeMessageWithAttachment();

        $this->getJson("/api/v1/team-chat/messages/{$message->id}/attachment")
            ->assertUnauthorized();
    }

    public function test_a_deleted_message_stops_serving_its_attachment(): void
    {
        // Deleting a message has to take the picture with it, or "delete" means
        // nothing to the person who sent it by mistake.
        $message = $this->makeMessageWithAttachment();

        $message->forceFill(['content_deleted_at' => now()])->save();

        $this->actingAs($this->midwife, 'sanctum')
            ->get("/api/v1/team-chat/messages/{$message->id}/attachment")
            ->assertNotFound();
    }

    public function test_the_kinds_staff_actually_need_to_send_are_accepted(): void
    {
        // A laboratory result is a PDF, a wound is easier shown in a few
        // seconds of video than described, and somebody with one hand full
        // can speak but cannot type. Photographs alone was too narrow.
        Storage::fake('private');

        $cases = [
            ['result.pdf', 'file'],
            ['wound.mp4', 'video'],
            ['voice-note.m4a', 'audio'],
            ['swelling.jpg', 'image'],
        ];

        foreach ($cases as [$name, $expected]) {
            $kind = $this->actingAs($this->nurse, 'sanctum')
                ->postJson('/api/v1/team-chat/attachments', [
                    'image' => UploadedFile::fake()->create($name, 120),
                ])
                ->assertCreated()
                ->json('data.attachment_meta.kind');

            $this->assertSame($expected, $kind, "{$name} was filed as {$kind}");
        }
    }

    public function test_a_file_type_that_has_no_business_here_is_refused(): void
    {
        Storage::fake('private');

        $this->actingAs($this->nurse, 'sanctum')
            ->postJson('/api/v1/team-chat/attachments', [
                'image' => UploadedFile::fake()->create('installer.exe', 40),
            ])
            ->assertStatus(422);
    }

    public function test_the_size_ceiling_is_per_kind_not_one_number_for_everything(): void
    {
        // 25 MB of video is a short clip; a 25 MB photograph is a mistake.
        // A single generous cap would wave the second one through.
        Storage::fake('private');

        $this->actingAs($this->nurse, 'sanctum')
            ->postJson('/api/v1/team-chat/attachments', [
                'image' => UploadedFile::fake()->create('huge.jpg', 20 * 1024),
            ])
            ->assertStatus(422);

        $this->actingAs($this->nurse, 'sanctum')
            ->postJson('/api/v1/team-chat/attachments', [
                'image' => UploadedFile::fake()->create('clip.mp4', 20 * 1024),
            ])
            ->assertCreated();
    }

    public function test_a_group_picture_must_still_be_a_picture(): void
    {
        Storage::fake('public');

        $this->actingAs($this->nurse, 'sanctum')
            ->postJson('/api/v1/team-chat/attachments', [
                'image' => UploadedFile::fake()->create('notes.pdf', 40),
                'purpose' => 'group_image',
            ])
            ->assertStatus(422);
    }
    private function makeMessageWithAttachment(): Message
    {
        Storage::fake('private');

        $path = SensitiveFiles::store(
            UploadedFile::fake()->image('wound.jpg'),
            'chat/attachments'
        );

        return Message::create([
            'conversation_id' => $this->convo->id,
            'sender_id' => $this->nurse->user_id,
            'body' => 'Please look at this.',
            'attachment_path' => $path,
        ]);
    }

    private function makeStaff(string $role): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        return User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Tester' . ($this->phone + 1),
            'mobile_number' => sprintf('0918100%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }
}
