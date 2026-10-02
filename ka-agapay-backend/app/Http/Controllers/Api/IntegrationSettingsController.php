<?php
// app/Http/Controllers/Api/IntegrationSettingsController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditActions;
use App\Services\Audit\AuditService;
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

    public function __construct(
        private readonly IntegrationTester $tester,
        private readonly AuditService $audit,
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

        $value = IntegrationCredentials::currentValue($integration, $field);

        if ($value === '') {
            return response()->json(['message' => 'No key is set for this field.'], 404);
        }

        $this->audit->warning('settings', AuditActions::INTEGRATION_REVEALED, [
            'subject_type' => 'integration',
            'subject_label' => "{$label} — {$fieldLabel}",
            'metadata' => ['integration' => $integration, 'field' => $field],
        ], $request);

        return response()
            ->json(['value' => $value, 'visible_seconds' => self::REVEAL_VISIBLE_SECONDS])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
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
