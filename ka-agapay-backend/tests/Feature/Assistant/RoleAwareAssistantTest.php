<?php
// tests/Feature/Assistant/RoleAwareAssistantTest.php
//
// The staff assistant answers each role for what that role can do (Nov 2026).
//
//   - the model is told who it is talking to, which screens they open, and
//     which actions belong to someone else and who
//   - a screen their role cannot open is answered with who can, never its
//     steps -- with the model, and when the model is down
//   - data lookups are offered only to roles that can see that data, and
//     refused if asked for anyway
//   - the assistant never opens a page the role cannot use
//   - a resident cannot ask for the staff assistant
//   - RoleGuide agrees with the server's own route rules

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Models\UserRole;
use App\Services\Ai\AssistantTools;
use App\Services\Ai\RoleGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleAwareAssistantTest extends TestCase
{
    use RefreshDatabase;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
    }

    public function test_the_model_is_told_who_it_is_talking_to(): void
    {
        $bhw = RoleGuide::promptFor($this->makeUser('bhw'));

        $this->assertStringContainsString('WHO YOU ARE TALKING TO: a Barangay Health Worker (BHW).', $bhw);
        $this->assertStringContainsString('Inventory (', $bhw, 'Inventory is listed among the screens a BHW cannot open.');
        $this->assertStringContainsString('issuing an e-prescription or lab request (Doctor, MHO (Municipal Health Officer) or Super Admin)', $bhw);
        $this->assertStringContainsString('barangay events', $bhw);

        $doctor = RoleGuide::promptFor($this->makeUser('doctor'));
        $this->assertStringNotContainsString('issuing an e-prescription', $doctor, 'A doctor issues prescriptions.');
        $this->assertStringContainsString('RHU Facilities (Super Admin)', $doctor);

        $this->assertSame('', RoleGuide::promptFor($this->makeUser('resident')));
    }

    public function test_a_closed_screen_is_answered_with_who_can_not_with_its_steps(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiSays('Ang Inventory ay para sa clinical staff.'))]);
        $bhw = $this->makeUser('bhw');

        // The quick, rule-based answer.
        $reply = $this->actingAs($bhw)
            ->postJson('/api/v1/chat/message', ['message' => 'Paano mag-adjust ng stock sa inventory?', 'audience' => 'staff'])
            ->assertOk()
            ->json('message.content');

        $this->assertStringContainsString('not available to your role', $reply);
        $this->assertStringNotContainsString('I-click ang **Inventory** button', $reply);

        // What the model is given for the same screen.
        $gemini = app(\App\Services\Ai\GeminiService::class);
        (new \ReflectionProperty($gemini, 'actor'))->setValue($gemini, $bhw);
        $prompt = (new \ReflectionMethod($gemini, 'buildUserPrompt'))
            ->invoke($gemini, 'Where do I see expiring supplies in the inventory?', 'staff', []);

        $this->assertStringContainsString('the Inventory screen is NOT available', $prompt);
        $this->assertStringContainsString('WHO YOU ARE TALKING TO', $prompt);
        $this->assertStringNotContainsString('Steps, in this order', $prompt);
    }

    public function test_with_the_model_down_the_closed_screen_answer_still_holds(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $reply = $this->actingAs($this->makeUser('bhw'))
            ->postJson('/api/v1/chat/message', ['message' => 'Paano mag-adjust ng stock sa inventory?', 'audience' => 'staff'])
            ->assertOk()
            ->json();

        $this->assertStringContainsString('not available to your role', json_encode($reply));
    }

    public function test_data_lookups_follow_the_role(): void
    {
        $tools = new AssistantTools();
        $bhw = $this->makeUser('bhw');
        $nurse = $this->makeUser('nurse');

        $names = fn (User $user) => array_column($tools->declarations($user), 'name');

        $this->assertNotContains('low_stock_medicines', $names($bhw));
        $this->assertNotContains('appointment_counts', $names($bhw));
        $this->assertContains('queue_status', $names($bhw));
        $this->assertContains('low_stock_medicines', $names($nurse));

        $this->assertArrayHasKey('error', $tools->run('low_stock_medicines', [], $bhw));
    }

    public function test_the_assistant_never_opens_a_page_the_role_cannot_use(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiSays('Para po iyan sa MHO.'))]);

        $this->actingAs($this->makeUser('nurse'))
            ->postJson('/api/v1/chat/message', ['message' => 'how many users are pending?', 'audience' => 'staff'])
            ->assertOk()
            ->assertJsonPath('suggested_action', null);

        $this->assertFalse(RoleGuide::allowsAction($this->makeUser('bhw'), 'open_inventory'));
        $this->assertTrue(RoleGuide::allowsAction($this->makeUser('nurse'), 'open_queue'));
        $this->assertFalse(RoleGuide::allowsAction($this->makeUser('nurse'), 'open_analytics'));
    }

    public function test_a_resident_cannot_ask_for_the_staff_assistant(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiSays('Magandang araw po.'))]);

        $this->actingAs($this->makeUser('resident'))
            ->postJson('/api/v1/chat/message', ['message' => 'how many patients in the queue today?', 'audience' => 'staff'])
            ->assertOk();

        Http::assertSent(function (ClientRequest $request) {
            $data = $request->data();

            return empty($data['tools']) && !str_contains(json_encode($data), 'RHU Staff Assistant');
        });
    }

    public function test_role_guide_agrees_with_the_routes(): void
    {
        $checks = [
            ['GET', 'api/v1/analytics/overview', 'analytics'],
            ['GET', 'api/v1/admin/users', 'users'],
            ['GET', 'api/v1/admin/appointments', 'appointments'],
            ['GET', 'api/v1/admin/consultations', 'consultations'],
            ['GET', 'api/v1/admin/events', 'events'],
            ['GET', 'api/v1/patients/registry', 'patients'],
            ['GET', 'api/v1/admin/sms/account', 'sms'],
            ['GET', 'api/v1/admin/settings', 'settings'],
        ];

        foreach ($checks as [$method, $uri, $screen]) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));

            $this->assertNotNull($route, "{$method} {$uri} is gone; update RoleGuide.");

            $fromRoute = collect($route->gatherMiddleware())
                ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'role:'))
                ->flatMap(fn ($m) => explode(',', substr($m, 5)))
                ->sort()->values()->all();

            $guide = collect(RoleGuide::screenRoles()[$screen])->unique()->sort()->values()->all();

            $this->assertSame(
                [],
                array_values(array_diff($guide, $fromRoute)),
                "RoleGuide lets roles into {$screen} that {$uri} refuses."
            );
        }
    }

    private function geminiSays(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']]];
    }

    private function makeUser(string $role): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        return User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Role' . ($this->phone + 1),
            'mobile_number' => sprintf('0917700%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }
}
