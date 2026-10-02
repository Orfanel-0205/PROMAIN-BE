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

/**
 * The API-keys section of Settings. Super admin only.
 *
 *   GET    /api/v1/admin/settings/integrations                 where each key comes from
 *   POST   /api/v1/admin/settings/integrations/{integration}/test   try values, save nothing
 *   PUT    /api/v1/admin/settings/integrations/{integration}        test, then save if it passed
 *   DELETE /api/v1/admin/settings/integrations/{integration}        go back to the server's .env
 *
 * No response ever contains a key. See IntegrationCredentials for how a saved
 * key reaches the services, and IntegrationTester for what each test proves.
 */
class IntegrationSettingsController extends Controller
{
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
