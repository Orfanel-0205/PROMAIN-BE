<?php
// tests/Feature/Assistant/TranscriptionTest.php
//
// AI dictation for staff in the languages of Malasiqui (Nov 2026).
//
//   - staff get back the words as spoken, and the model is told to expect
//     Pangasinan, Tagalog, Ilocano and English mixed, with the barangays
//   - residents cannot use it
//   - when the model is down the answer says so, with what to do instead
//   - a recording with no speech is refused rather than returned empty
//   - a resident's voice note asks the model to restate what it heard

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Models\UserRole;
use App\Services\Ai\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranscriptionTest extends TestCase
{
    use RefreshDatabase;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);
        Cache::forget('gemini.transcribe.barangays');
    }

    public function test_staff_get_the_words_as_spoken_in_the_languages_of_malasiqui(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiSays([
                'transcript' => 'Say pasyente et walay lagnat na duaran agew, BP 130/90, binigyan ng Paracetamol.',
                'languages' => ['pangasinan', 'tagalog', 'english'],
            ])),
        ]);

        $this->actingAs($this->makeUser('nurse'))
            ->post('/api/v1/ai/transcribe', [
                'audio' => UploadedFile::fake()->createWithContent('dictation.wav', $this->wav()),
                'hint' => 'pag',
                'context' => 'soap',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('transcript', 'Say pasyente et walay lagnat na duaran agew, BP 130/90, binigyan ng Paracetamol.')
            ->assertJsonPath('languages', ['pangasinan', 'tagalog', 'english']);

        $barangay = DB::table('barangays')->orderBy('name')->value('name');

        Http::assertSent(function (ClientRequest $request) use ($barangay) {
            $parts = $request->data()['contents'][0]['parts'];
            $prompt = $parts[0]['text'];

            return str_contains($prompt, 'Pangasinan, Tagalog, Ilocano or English')
                && str_contains($prompt, 'mostly speak Pangasinan')
                && str_contains($prompt, 'never translate')
                && str_contains($prompt, $barangay)
                && $parts[1]['inline_data']['mime_type'] === 'audio/wav';
        });
    }

    public function test_residents_cannot_use_it(): void
    {
        Http::fake();

        $this->actingAs($this->makeUser('resident'))
            ->post('/api/v1/ai/transcribe', [
                'audio' => UploadedFile::fake()->createWithContent('dictation.wav', $this->wav()),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_when_the_model_is_down_it_says_what_to_do_instead(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $this->actingAs($this->makeUser('doctor'))
            ->post('/api/v1/ai/transcribe', [
                'audio' => UploadedFile::fake()->createWithContent('dictation.wav', $this->wav()),
            ], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Type it'));
    }

    public function test_a_recording_with_no_speech_is_refused(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiSays(['transcript' => '', 'languages' => []])),
        ]);

        $this->actingAs($this->makeUser('midwife'))
            ->post('/api/v1/ai/transcribe', [
                'audio' => UploadedFile::fake()->createWithContent('dictation.wav', $this->wav()),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_a_residents_voice_note_asks_the_model_to_restate_what_it_heard(): void
    {
        $method = new \ReflectionMethod(GeminiService::class, 'attachmentInstructions');
        $text = $method->invoke(app(GeminiService::class), [['mime' => 'audio/mp4', 'data' => 'x']]);

        $this->assertStringContainsString('Pangasinan, Tagalog, Ilocano and English', $text);
        $this->assertStringContainsString('restating their question as you understood it', $text);
    }

    private function geminiSays(array $json): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]]];
    }

    /** A short, valid 16 kHz mono WAV, so the server recognises it as audio. */
    private function wav(): string
    {
        $samples = '';
        for ($i = 0; $i < 1600; $i++) {
            $samples .= pack('v', (int) (3000 * sin($i / 8)));
        }

        return 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            . 'data' . pack('V', strlen($samples)) . $samples;
    }

    private function makeUser(string $role): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        return User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Dictation' . ($this->phone + 1),
            'mobile_number' => sprintf('0917600%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }
}
