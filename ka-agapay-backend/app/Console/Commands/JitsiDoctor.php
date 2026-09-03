<?php
// app/Console/Commands/JitsiDoctor.php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Video\JitsiTokenService;
use Firebase\JWT\JWT;
use Illuminate\Console\Command;

/**
 * Diagnose the Jitsi/JaaS configuration on a box WITHOUT ever revealing key
 * material. Reports presence, path, permissions and validity only, then mints a
 * throwaway token and verifies it against the public half of the key.
 *
 * Usage:  php artisan jitsi:doctor
 *         php artisan jitsi:doctor --user=12
 */
class JitsiDoctor extends Command
{
    protected $signature = 'jitsi:doctor {--user= : Mint a sample token as this user id}';

    protected $description = 'Check the Jitsi/JaaS video configuration (never prints key material)';

    public function handle(JitsiTokenService $tokens): int
    {
        $this->info('Jitsi / JaaS configuration');
        $this->line(str_repeat('-', 62));

        $config = $tokens->describeConfiguration();

        foreach ($config as $key => $value) {
            $shown = match (true) {
                is_bool($value) => $value ? 'yes' : 'NO',
                $value === null => '-',
                default => (string) $value,
            };

            $this->line(sprintf('  %-28s %s', $key, $shown));
        }

        $this->newLine();

        $problems = [];

        if (!$config['php_jwt_installed']) {
            $problems[] = 'firebase/php-jwt is missing — no token can be signed.';
        }

        if ($config['provider'] === 'jaas') {
            if (!$config['app_id_set']) {
                $problems[] = 'JITSI_APP_ID (JaaS AppID/tenant) is not set.';
            }

            if (!$config['key_id_set']) {
                $problems[] = 'JaaS key id is not set — expected JITSI_API_KEY (or JITSI_API_KEY_ID).';
            }

            if (!$config['private_key_valid_rsa']) {
                $problems[] = 'No usable RSA private key — expected JITSI_PRIVATE_KEY '
                    . '(inline PEM or a path to the .pem).';
            }

            if ($config['private_key_path'] && $config['private_key_permissions']
                && !in_array($config['private_key_permissions'], ['0400', '0440', '0600', '0640'], true)) {
                $this->warn(sprintf(
                    '  ! Private key file mode is %s — tighten it to 0400/0600 (owned by the web user).',
                    $config['private_key_permissions']
                ));
            }
        }

        if ($config['provider'] !== 'meet_public_demo' && !$config['jwt_enabled']) {
            $this->warn('  ! JITSI_JWT_ENABLED is off; rooms will be joined unauthenticated.');
        }

        // ---- live signing check -------------------------------------------
        $this->info('Token check');
        $this->line(str_repeat('-', 62));

        $user = null;

        if ($this->option('user')) {
            $user = User::where('user_id', (int) $this->option('user'))->first();

            if (!$user) {
                $this->error('  User not found: ' . $this->option('user'));

                return self::FAILURE;
            }
        }

        $identity = $tokens->identityForUser($user, true);
        $token = $tokens->issueToken('kaagapay-doctor-probe', $identity);

        if (empty($token)) {
            $this->error('  FAILED: no token was produced.');
            $problems[] = 'Token minting returned null.';
        } else {
            $this->line(sprintf('  token issued              yes (%d chars)', strlen($token)));
            $this->line(sprintf('  identity                  id=%s name=%s', $identity['id'], $identity['name']));

            // Decode the header/payload WITHOUT verifying, purely to show the
            // claims an operator needs to confirm. No key material involved.
            $parts = explode('.', $token);

            if (count($parts) === 3) {
                $header = json_decode($this->b64($parts[0]), true) ?: [];
                $claims = json_decode($this->b64($parts[1]), true) ?: [];

                $this->line(sprintf('  alg / kid                 %s / %s',
                    $header['alg'] ?? '?',
                    $header['kid'] ?? 'MISSING'));
                $this->line(sprintf('  aud / iss                 %s / %s',
                    $claims['aud'] ?? '?',
                    $claims['iss'] ?? '?'));

                // 'sub' must equal the JaaS AppID or 8x8 rejects the join. This
                // previously printed the literal word 'set' from an
                // isset() presence check, so an operator comparing the token
                // against the 8x8 dashboard had nothing to compare -- and a
                // wrong AppID would have looked identical to a correct one.
                // Neither the AppID nor the key id is secret: both travel in
                // the token, and the AppID is in every room URL.
                $configuredAppId = (string) config('services.jitsi.app_id', '');
                $sub = $claims['sub'] ?? null;

                $subLine = match (true) {
                    $sub === null => 'MISSING',
                    $configuredAppId === '' => $sub . '   (JITSI_APP_ID is unset, cannot compare)',
                    $sub === $configuredAppId => $sub . '   (matches JITSI_APP_ID)',
                    default => $sub . '   (MISMATCH - JITSI_APP_ID is ' . $configuredAppId . ')',
                };

                $this->line(sprintf('  sub (JaaS AppID)          %s', $subLine));
                $this->line(sprintf('  room claim                %s', $claims['room'] ?? '?'));
                $this->line(sprintf('  context.user.name         %s', $claims['context']['user']['name'] ?? '?'));
                $this->line(sprintf('  expires in                %d min',
                    (int) round((($claims['exp'] ?? time()) - time()) / 60)));
            }
        }

        $this->newLine();

        if ($problems) {
            $this->error('Problems found:');
            foreach ($problems as $problem) {
                $this->line('  - ' . $problem);
            }

            return self::FAILURE;
        }

        $this->info('Configuration looks usable.');

        return self::SUCCESS;
    }

    private function b64(string $segment): string
    {
        $remainder = strlen($segment) % 4;

        if ($remainder) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($segment, '-_', '+/'));
    }
}
