<?php
// tests/Feature/Assistant/ChatPhotoTest.php
//
// A photo sent to the assistant (Oct 2026). Full-size camera photos made
// Gemini time out -- 51 seconds, then the generic "Nandito ako para
// tumulong…", which reads as the assistant ignoring the picture. Now:
//   - the photo is made small before it is sent (ChatImage);
//   - if the model still cannot be reached, the reply says the photo could
//     not be read and asks for it again;
//   - the reply names the saved question's id, so the app can keep showing
//     the photo when the chat is reopened.

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Models\UserRole;
use App\Support\ChatImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.gemini_api_key' => 'test-key']);
        Cache::forget('gemini_cooldown');

        $this->resident = User::create([
            'role_id' => UserRole::firstOrCreate(['name' => 'resident'], ['permissions' => []])->role_id,
            'first_name' => 'Maria',
            'last_name' => 'Reyes',
            'mobile_number' => '09171300001',
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }

    public function test_a_camera_size_photo_is_made_small_before_it_is_sent(): void
    {
        $photo = UploadedFile::fake()->image('rash.jpg', 4000, 3000);
        $bytes = file_get_contents($photo->getRealPath());

        $small = ChatImage::shrink($bytes, 'image/jpeg');
        [$width, $height] = getimagesizefromstring($small['bytes']);

        $this->assertSame('image/jpeg', $small['mime']);
        $this->assertSame(ChatImage::MAX_EDGE, max($width, $height));
        $this->assertSame(1200, min($width, $height), 'The shape is kept.');

        // Anything it cannot read comes back as it was.
        $this->assertSame(['bytes' => 'not an image', 'mime' => 'image/jpeg'], ChatImage::shrink('not an image', 'image/jpeg'));
    }

    public function test_the_reply_names_the_saved_question_so_the_app_can_keep_the_photo(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Mukhang pantal po ito.']]], 'finishReason' => 'STOP']],
        ])]);

        $response = $this->actingAs($this->resident)->post('/api/v1/chat/message', [
            'message' => 'Ano po ito?',
            'attachment' => UploadedFile::fake()->image('rash.jpg', 3000, 2000),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotEmpty($response->json('user_message.id'));
        $this->assertSame('user', $response->json('user_message.role'));
        $this->assertStringContainsString('pantal', $response->json('message.content'));

        // What reached Gemini was the small photo.
        Http::assertSent(function ($request) {
            $data = collect($request['contents'] ?? [])->last()['parts'][1]['inline_data']['data'] ?? '';
            [$width, $height] = getimagesizefromstring(base64_decode($data));

            return max($width, $height) <= ChatImage::MAX_EDGE;
        });
    }

    public function test_if_the_photo_cannot_be_read_the_reply_says_so(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $response = $this->actingAs($this->resident)->post('/api/v1/chat/message', [
            'attachment' => UploadedFile::fake()->image('rash.jpg', 1200, 900),
        ], ['Accept' => 'application/json'])->assertOk();

        $reply = (string) $response->json('message.content');
        $this->assertStringContainsString('hindi ko po nabasa ang larawan', $reply);
        $this->assertStringNotContainsString('Nandito ako para tumulong', $reply);
    }
}
