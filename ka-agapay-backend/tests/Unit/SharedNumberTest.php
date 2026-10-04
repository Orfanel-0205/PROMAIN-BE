<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * One mobile number, one staff account and one resident account.
 *
 * RHU staff are residents too. The rule only works if every place that finds
 * an account by number asks for the right kind: the admin sign-in must reach
 * the staff account, the app the resident one, and every uniqueness check
 * must compare within one kind. A place that still compares across both would
 * either refuse a legitimate second account or sign someone in to the wrong
 * one -- so each is held here.
 */
class SharedNumberTest extends TestCase
{
    private const APP = __DIR__ . '/../../app';

    private function source(string $file): string
    {
        return (string) file_get_contents(self::APP . '/' . $file);
    }

    #[Test]
    #[TestDox('only resident and patient are resident roles')]
    public function resident_roles(): void
    {
        // Everything else needs approval and signs in to the admin website.
        $this->assertSame(['resident', 'patient'], User::RESIDENT_ROLES);
    }

    #[Test]
    #[TestDox('the database allows a number once per kind of account')]
    public function the_index_is_per_kind(): void
    {
        $migration = (string) file_get_contents(
            __DIR__ . '/../../database/migrations/2026_10_04_010000_allow_one_number_per_staff_and_resident_account.php'
        );

        $this->assertStringContainsString('DROP INDEX IF EXISTS users_mobile_number_unique', $migration);
        $this->assertStringContainsString(
            'CREATE UNIQUE INDEX users_mobile_number_kind_unique ON users (mobile_number, is_staff) WHERE deleted_at IS NULL',
            $migration
        );
    }

    #[Test]
    #[TestDox('each sign-in reaches its own kind of account first')]
    public function sign_ins_prefer_their_kind(): void
    {
        $auth = $this->source('Http/Controllers/Api/AuthController.php');

        $admin = (string) substr($auth, (int) strpos($auth, 'public function adminLogin('), 4000);
        $this->assertStringContainsString('->preferKind(true)', $admin, 'The admin sign-in can land on a resident account.');

        $app = (string) substr($auth, (int) strpos($auth, 'public function login('), 3000);
        $this->assertStringContainsString('->preferKind(false)', $app, 'The app sign-in can land on a staff account.');

        // Forgot password resets only the screen's own kind.
        $this->assertStringContainsString("\$this->findUserByLogin(\$login, \$audience === 'admin')", $auth);
    }

    #[Test]
    #[TestDox('no uniqueness check on the number compares across both kinds')]
    public function uniqueness_is_per_kind(): void
    {
        foreach ([
            'Http/Controllers/Api/AuthController.php',
            'Http/Controllers/Api/AdminRegistrationController.php',
            'Http/Controllers/Api/AdminProfileController.php',
            'Http/Controllers/Api/ProfileController.php',
            'Http/Requests/RegisterRequest.php',
        ] as $file) {
            $this->assertStringNotContainsString(
                "Rule::unique('users', 'mobile_number')",
                $this->source($file),
                "{$file} still treats a number as unique across staff and resident accounts."
            );
        }

        $users = $this->source('Http/Controllers/Api/AdminUserController.php');
        $this->assertSame(2, substr_count($users, "->where('mobile_number', \$mobile)"), 'AdminUserController gained a number lookup.');
        $this->assertSame(2, substr_count($users, '->ofKind('), 'An AdminUserController number check is not per kind.');
    }

    #[Test]
    #[TestDox('the model keeps the kind in step with the role and refuses a clash with a 422')]
    public function the_model_guards_every_path(): void
    {
        $model = $this->source('Models/User.php');
        $booted = (string) substr($model, (int) strpos($model, 'protected static function booted('), 2000);

        $this->assertStringContainsString("\$user->isDirty('role_id')", $booted);
        $this->assertStringContainsString('self::roleIsStaff($user->role_id)', $booted);
        $this->assertStringContainsString('ValidationException::withMessages', $booted);
    }
}
