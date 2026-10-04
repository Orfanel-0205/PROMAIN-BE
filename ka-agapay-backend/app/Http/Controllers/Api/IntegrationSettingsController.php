<?php
// app/Http/Controllers/Api/IntegrationSettingsController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditActions;
use App\Services\Audit\AuditService;
use App\Services\Auth\VerificationCodes;
use App\Services\Integrations\IntegrationTester;
use App\Support\IntegrationCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The API-keys section of Settings. Super admin only.
 *
 *   GET    /api/v1/admin/settings/integrations                 where each key comes from
 *   POST   /api/v1/admin/settings/integrations/{integration}/reveal one key in full, with the password
 *   POST   /api/v1/admin/settings/integrations/{integration}/test   try values, save nothing
 *   PUT    /api/v1/admin/settings/integrations/{integration}        test, then save if it passed
 *   DELETE /api/v1/admin/settings/integrations/{integration}        go back to the server's .env
 *
 * No response ever contains a key. See IntegrationCredentials for how a saved
 * key reaches the services, and IntegrationTester for what each test proves.
 */
class IntegrationSettingsController extends Controller
{
    /** Wrong passwords allowed before viewing keys is locked. */
    private const REVEAL_MAX_ATTEMPTS = 5;

    /** How long viewing stays locked after too many wrong passwords. */
    private const REVEAL_LOCK_SECONDS = 900;

    /** How long the page shows a revealed key before hiding it again. */
    private const REVEAL_VISIBLE_SECONDS = 30;

    /** No viewing keys for this long after the account's mobile number changes. */
    private const MOBILE_CHANGE_COOLDOWN_HOURS = 24;

    public function __construct(
        private readonly IntegrationTester $tester,
        private readonly AuditService $audit,
        private readonly VerificationCodes $codes,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['integrations' => IntegrationCredentials::status()]);
    }

    public function test(Request $request, string $integration): JsonResponse
    {
        if (!IntegrationCredentials::exists($integration)) {
            return $this->unknown();
        }

        $submitted = IntegrationCredentials::submittedFields($integration, $this->fieldsFrom($request, $integration));

        $result = $this->tester->test(
            $integration,
            IntegrationCredentials::candidate($integration, $submitted),
            $submitted,
        );

        return response()->json(['result' => $result]);
    }

    public function update(Request $request, string $integration): JsonResponse
    {
        if (!IntegrationCredentials::exists($integration)) {
            return $this->unknown();
        }

        $submitted = IntegrationCredentials::submittedFields($integration, $this->fieldsFrom($request, $integration));

        if ($submitted === []) {
            return response()->json([
                'message' => 'Nothing to save. Fill in at least one field; blank fields keep their current value.',
            ], 422);
        }

        // Test the combination that would be in effect after saving.
        $result = $this->tester->test(
            $integration,
            IntegrationCredentials::candidate($integration, $submitted),
            $submitted,
        );

        if (!($result['ok'] ?? false)) {
            return response()->json([
                'message' => 'Not saved: ' . $result['message'],
                'result' => $result,
            ], 422);
        }

        $user = $request->user();
        $saved = IntegrationCredentials::save($integration, $submitted, $user?->user_id ?? $user?->getKey());

        // Which fields changed, never their values.
        $this->audit->info('settings', AuditActions::INTEGRATION_UPDATED, [
            'subject_type' => 'integration',
            'subject_label' => IntegrationCredentials::REGISTRY[$integration]['label'],
            'metadata' => ['integration' => $integration, 'fields' => $saved],
        ], $request);

        return response()->json([
            'message' => 'Saved. ' . $result['message'],
            'result' => $result,
            'integrations' => IntegrationCredentials::status(),
        ]);
    }

    /**
     * Show one key in full, after the super admin re-enters their password.
     *
     *   POST /api/v1/admin/settings/integrations/{integration}/reveal
     *        { "field": "api_key", "password": "..." }
     *
     * WHY A PASSWORD, WHEN THE CALLER IS ALREADY SIGNED IN
     * Being signed in proves someone holds a valid session token, and a token
     * can be stolen -- copied from a shared RHU computer, or lifted by a
     * script running in the page. A key revealed to that token is a key
     * leaked. The password is the one thing a stolen token does not carry.
     *
     * THE REST OF THE PROTECTION
     *   One key per request, so a single reveal cannot dump every account.
     *   Five wrong passwords lock reveals for fifteen minutes for that user,
     *   so the password cannot be guessed through this endpoint.
     *   Every reveal, and every refusal, is in the audit log as a warning --
     *   which key, never the value or the password.
     *   POST with no-store, so neither the password nor the key lands in a
     *   URL, the browser cache, or the server's access log.
     *   The 8x8 private key is never revealable (see NOT_REVEALABLE).
     *
     * A wrong password answers 422, not 401: the admin treats any 401 as an
     * expired session and signs the user out, which would turn a typo into
     * a logout.
     */
    public function reveal(Request $request, string $integration): JsonResponse
    {
        if (!IntegrationCredentials::exists($integration)) {
            return $this->unknown();
        }

        $validated = $request->validate([
            'field'    => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $field = $validated['field'];
        $user = $request->user();
        $label = IntegrationCredentials::REGISTRY[$integration]['label'];
        $fieldLabel = IntegrationCredentials::fields($integration)[$field]['label'] ?? $field;

        if (!IntegrationCredentials::isRevealable($integration, $field)) {
            return response()->json([
                'message' => $integration === 'jaas' && $field === 'private_key'
                    ? 'The 8x8 private key cannot be viewed. Its fingerprint identifies it; to change it, generate a new key pair in the 8x8 console and paste the new private key.'
                    : 'This value cannot be viewed here.',
            ], 422);
        }

        $limiterKey = 'integration-reveal:' . ($user->user_id ?? $user->getKey());

        if (RateLimiter::tooManyAttempts($limiterKey, self::REVEAL_MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limiterKey) / 60);

            $this->audit->warning('settings', AuditActions::INTEGRATION_REVEAL_DENIED, [
                'subject_type' => 'integration',
                'subject_label' => "{$label} — {$fieldLabel}",
                'metadata' => ['integration' => $integration, 'field' => $field, 'reason' => 'locked'],
            ], $request);

            return response()->json([
                'message' => "Too many incorrect passwords. Viewing keys is locked for {$minutes} more minute" . ($minutes === 1 ? '' : 's') . '.',
                'locked' => true,
                'retry_after_minutes' => $minutes,
            ], 429);
        }

        $hash = (string) ($user->getAuthPassword() ?? '');

        if ($hash === '' || !Hash::check($validated['password'], $hash)) {
            RateLimiter::hit($limiterKey, self::REVEAL_LOCK_SECONDS);
            $left = RateLimiter::remaining($limiterKey, self::REVEAL_MAX_ATTEMPTS);

            $this->audit->warning('settings', AuditActions::INTEGRATION_REVEAL_DENIED, [
                'subject_type' => 'integration',
                'subject_label' => "{$label} — {$fieldLabel}",
                'metadata' => ['integration' => $integration, 'field' => $field, 'reason' => 'wrong_password', 'attempts_left' => $left],
            ], $request);

            return response()->json([
                'message' => $left > 0
                    ? "Incorrect password. {$left} attempt" . ($left === 1 ? '' : 's') . ' left before viewing is locked for 15 minutes.'
                    : 'Incorrect password. Viewing keys is now locked for 15 minutes.',
                'attempts_left' => $left,
            ], 422);
        }

        RateLimiter::clear($limiterKey);

        if (IntegrationCredentials::currentValue($integration, $field) === '') {
            return response()->json(['message' => 'No key is set for this field.'], 404);
        }

        /*
         * The password is right; now the phone.
         *
         * A password can be filled in by a browser that saved it, for whoever
         * is sitting at an unlocked computer. A code texted to the super
         * admin's own phone cannot. The key is released by revealConfirm(),
         * only to this same account, only for this same key.
         */
        if ($user->mobile_changed_at && $user->mobile_changed_at->gt(now()->subHours(self::MOBILE_CHANGE_COOLDOWN_HOURS))) {
            // Otherwise someone at the computer could point the account at
            // their own phone and then receive the code.
            $until = $user->mobile_changed_at->copy()->addHours(self::MOBILE_CHANGE_COOLDOWN_HOURS);

            return response()->json([
                'message' => 'The mobile number on your account changed recently, so viewing keys is paused until '
                    . $until->timezone('Asia/Manila')->format('M j, g:i A') . '. Replacing a key still works.',
            ], 423);
        }

        // Likewise after "Forgot password": whoever reset it has the phone
        // or the mailbox, which is not yet proof of being the super admin.
        $resetAt = $user->password_reset_at;

        if ($resetAt && $resetAt->gt(now()->subHours(self::MOBILE_CHANGE_COOLDOWN_HOURS))) {
            $until = $resetAt->copy()->addHours(self::MOBILE_CHANGE_COOLDOWN_HOURS);

            return response()->json([
                'message' => 'Your password was reset with "Forgot password" recently, so viewing keys is paused until '
                    . $until->timezone('Asia/Manila')->format('M j, g:i A') . '. Replacing a key still works.',
            ], 423);
        }

        $issued = $this->codes->issue($user, VerificationCodes::PURPOSE_REVEAL_KEY, [
            'integration' => $integration,
            'field' => $field,
        ], $request);

        return match ($issued['status']) {
            'sent' => response()->json([
                'code_required' => true,
                'message' => "Enter the 6-digit code we sent to your mobile number ending in {$issued['masked_mobile']}.",
                'challenge' => $issued['challenge'],
                'masked_mobile' => $issued['masked_mobile'],
                'expires_in' => $issued['expires_in'],
                'resend_after' => $issued['resend_after'],
            ]),
            'no_mobile' => response()->json([
                'message' => 'Your account has no valid mobile number to send a code to. Add one in your profile to view keys. Replacing a key still works.',
            ], 422),
            'too_many' => response()->json([
                'message' => 'Too many codes have been sent to your phone. Try again later.',
            ], 429),
            default => response()->json([
                'message' => "We couldn't send a code right now, so the key cannot be shown. Try again in a few minutes.",
            ], 503),
        };
    }

    /**
     * The second step of viewing a key: the code from the super admin's phone.
     *
     *   POST /api/v1/admin/settings/integrations/{integration}/reveal/confirm
     *        { "challenge": "...", "code": "123456" }
     *
     * The code was issued to one account for one key. It releases nothing to
     * a different account, and nothing for a different key, even if both
     * were somehow in hand.
     */
    public function revealConfirm(Request $request, string $integration): JsonResponse
    {
        if (!IntegrationCredentials::exists($integration)) {
            return $this->unknown();
        }

        $validated = $request->validate([
            'challenge' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $user = $request->user();
        $result = $this->codes->verify($validated['challenge'], VerificationCodes::PURPOSE_REVEAL_KEY, $validated['code']);
        $issuedTo = $result['user'] ?? null;
        $context = $result['context'] ?? [];
        $field = (string) ($context['field'] ?? '');
        $label = IntegrationCredentials::REGISTRY[$integration]['label'];

        // A challenge belongs to the account it was issued to. Anything else
        // is treated exactly like an unknown challenge.
        if ($issuedTo !== null && (int) $issuedTo->user_id !== (int) $user->user_id) {
            return response()->json(['message' => 'This code has expired. Start again.', 'restart' => true], 410);
        }

        if ($result['status'] === 'wrong') {
            $left = (int) $result['attempts_left'];

            $this->audit->warning('settings', AuditActions::INTEGRATION_REVEAL_DENIED, [
                'subject_type' => 'integration',
                'subject_label' => $label,
                'metadata' => ['integration' => $integration, 'reason' => 'wrong_code', 'attempts_left' => $left],
            ], $request);

            return response()->json([
                'message' => $left > 0
                    ? "Incorrect code. {$left} attempt" . ($left === 1 ? '' : 's') . ' left.'
                    : 'Incorrect code. Start again to get a new one.',
                'attempts_left' => $left,
                'restart' => $left === 0,
            ], $left > 0 ? 422 : 410);
        }

        if ($result['status'] !== 'ok'
            || ($context['integration'] ?? null) !== $integration
            || !IntegrationCredentials::isRevealable($integration, $field)) {
            return response()->json(['message' => 'This code has expired. Start again.', 'restart' => true], 410);
        }

        $value = IntegrationCredentials::currentValue($integration, $field);

        if ($value === '') {
            return response()->json(['message' => 'No key is set for this field.'], 404);
        }

        $fieldLabel = IntegrationCredentials::fields($integration)[$field]['label'] ?? $field;

        $this->audit->warning('settings', AuditActions::INTEGRATION_REVEALED, [
            'subject_type' => 'integration',
            'subject_label' => "{$label} — {$fieldLabel}",
            'metadata' => ['integration' => $integration, 'field' => $field, 'verified_by' => 'password+sms_code'],
        ], $request);

        return response()
            ->json(['value' => $value, 'field' => $field, 'visible_seconds' => self::REVEAL_VISIBLE_SECONDS])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /** A fresh code for a reveal in progress. */
    public function revealResend(Request $request, string $integration): JsonResponse
    {
        $validated = $request->validate(['challenge' => ['required', 'string', 'max:64']]);

        $result = $this->codes->resend($validated['challenge'], VerificationCodes::PURPOSE_REVEAL_KEY);

        return match ($result['status']) {
            'sent' => response()->json([
                'message' => "We sent a new code to the number ending in {$result['masked_mobile']}.",
                'resend_after' => VerificationCodes::RESEND_AFTER_SECONDS,
            ]),
            'wait' => response()->json(['message' => "Wait {$result['wait']} seconds before asking for another code."], 429),
            'limit' => response()->json(['message' => 'No more codes can be sent for this request. Start again later.', 'restart' => true], 429),
            'send_failed' => response()->json(['message' => "We couldn't send a new code right now."], 503),
            default => response()->json(['message' => 'This request has expired. Start again.', 'restart' => true], 410),
        };
    }

    public function destroy(Request $request, string $integration): JsonResponse
    {
        if (!IntegrationCredentials::exists($integration)) {
            return $this->unknown();
        }

        $removed = IntegrationCredentials::clear($integration);

        $this->audit->warning('settings', AuditActions::INTEGRATION_RESET, [
            'subject_type' => 'integration',
            'subject_label' => IntegrationCredentials::REGISTRY[$integration]['label'],
            'metadata' => ['integration' => $integration, 'rows_removed' => $removed],
        ], $request);

        return response()->json([
            'message' => $removed > 0
                ? 'Saved values removed. The server defaults are in use again.'
                : 'Nothing was saved here; the server defaults were already in use.',
            'integrations' => IntegrationCredentials::status(),
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * Only this integration's own fields, each a bounded string.
     *
     * A PEM private key is about 1.7 KB, so 8 KB leaves room for any real key
     * while refusing anything that is clearly not one.
     *
     * @return array<string, mixed>
     */
    private function fieldsFrom(Request $request, string $integration): array
    {
        $rules = [];

        foreach (array_keys(IntegrationCredentials::fields($integration)) as $field) {
            $rules[$field] = ['nullable', 'string', 'max:8192'];
        }

        return $request->validate($rules);
    }

    private function unknown(): JsonResponse
    {
        return response()->json(['message' => 'Unknown integration.'], 404);
    }
}
