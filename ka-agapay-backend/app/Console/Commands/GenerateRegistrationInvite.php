<?php
// app/Console/Commands/GenerateRegistrationInvite.php
//
// Panelist evidence tool (Sir Ayco): generates a REAL signed staff
// registration link and prints the actual token / signature / expiry to the
// terminal — a literally screenshot-able proof that the mechanism works.
// Signing is canonical against APP_URL, so CLI-generated links verify
// identically to links generated from the admin UI.
//
//   php artisan registration:invite
//   php artisan registration:invite --for="Maria Santos" --mobile=09171234567 --minutes=30

namespace App\Console\Commands;

use App\Services\RegistrationInviteService;
use Illuminate\Console\Command;

class GenerateRegistrationInvite extends Command
{
    protected $signature = 'registration:invite
                            {--for= : Who the link is intended for (label shown to the Super Admin)}
                            {--mobile= : Lock the link to this PH mobile number (09XXXXXXXXX)}
                            {--minutes=10080 : Lifetime — 30, 1440 (1 day), or 10080 (7 days)}';

    protected $description = 'Generate a signed, unique, one-time staff registration invite link (Sir Ayco evidence)';

    public function handle(RegistrationInviteService $invites): int
    {
        if (!$invites->isEnabled()) {
            $this->error('registration_invites table is missing — run: php artisan migrate');

            return self::FAILURE;
        }

        $minutes = (int) $this->option('minutes');

        if (!in_array($minutes, RegistrationInviteService::ALLOWED_LIFETIMES, true)) {
            $this->warn('Lifetime must be 30, 1440, or 10080 minutes — using the 7-day default.');
            $minutes = RegistrationInviteService::DEFAULT_LIFETIME_MINUTES;
        }

        $result = $invites->generate(
            null,
            $this->option('for') ?: null,
            $this->option('mobile') ?: null,
            $minutes
        );

        $invite = $result['invite'];
        parse_str((string) parse_url($result['signed_api_url'], PHP_URL_QUERY), $query);

        $this->info('Signed one-time staff registration link generated.');
        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['Invite ID (DB row)', $invite->id],
            ['Intended for', $invite->intended_for ?? '—'],
            ['Mobile lock', $invite->mobile_number ?? '— (none)'],
            ['Expires at', $invite->expires_at->toDayDateTimeString()],
            ['Raw token (in link only)', $result['token']],
            ['Token SHA-256 (stored in DB)', $invite->token_hash],
            ['HMAC signature (Laravel signed URL)', (string) ($query['signature'] ?? '')],
        ]);
        $this->newLine();
        $this->line('<comment>Share THIS link (admin registration page):</comment>');
        $this->line($result['url']);
        $this->newLine();
        $this->line('<comment>Signed API verification URL (what the signature protects):</comment>');
        $this->line($result['signed_api_url']);
        $this->newLine();
        $this->line('One-time use: after a successful registration, used_at is set and the link is dead.');

        return self::SUCCESS;
    }
}
