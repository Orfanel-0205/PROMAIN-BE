<?php
// app/Http/Controllers/Api/RegistrationInviteController.php
//
// Panelist requirement (Sir Ayco) — Super-Admin-generated staff registration
// invitation links: signed (URL::temporarySignedRoute), unique, expiring,
// one-time-use. Generation/list/revoke are Super-Admin-only; verify is the
// public signed endpoint the /register SPA page replays its query against.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RegistrationInvite;
use App\Services\Audit\AuditService;
use App\Services\RegistrationInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegistrationInviteController extends Controller
{
    public function __construct(
        private readonly RegistrationInviteService $invites,
        private readonly AuditService $audit
    ) {
    }

    /**
     * POST /api/v1/admin/registration-invites  (super admin)
     *
     * Returns the FULL signed URL exactly once — it is never reconstructable
     * afterwards (only the token's SHA-256 hash is stored). The response is
     * the panelist's requested screenshot-able evidence: real token, real
     * signature, real expiry.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->invites->isEnabled()) {
            return response()->json([
                'message' => 'Registration invites are not available yet — run the registration_invites migration first.',
            ], 503);
        }

        $validated = $request->validate([
            'intended_for' => ['nullable', 'string', 'max:150'],
            'mobile_number' => ['nullable', 'regex:/^(\+?63|0)9\d{9}$/'],
            'expires_in_minutes' => ['nullable', 'integer', Rule::in(RegistrationInviteService::ALLOWED_LIFETIMES)],
        ], [
            'mobile_number.regex' => 'Use a valid PH mobile number (09XXXXXXXXX) or leave it blank.',
            'expires_in_minutes.in' => 'Link lifetime must be 30 minutes, 1 day, or 7 days.',
        ]);

        $result = $this->invites->generate(
            (int) ($request->user()->user_id ?? $request->user()->getKey()),
            $validated['intended_for'] ?? null,
            $validated['mobile_number'] ?? null,
            (int) ($validated['expires_in_minutes'] ?? RegistrationInviteService::DEFAULT_LIFETIME_MINUTES)
        );

        /** @var RegistrationInvite $invite */
        $invite = $result['invite'];

        $this->audit->log(
            $request,
            'registration_invite_generated',
            'registration',
            $invite,
            [],
            [
                'intended_for' => $invite->intended_for,
                'mobile_locked' => $invite->mobile_number !== null,
                'expires_at' => $invite->expires_at?->toIso8601String(),
            ],
            ['token_hash' => $invite->token_hash],
            'info',
            $invite->intended_for ?: ('Invite #' . $invite->id)
        );

        return response()->json([
            'message' => 'Registration link generated. Copy it now — for security it cannot be shown again.',
            'data' => [
                'id' => $invite->id,
                'url' => $result['url'],
                'signed_api_url' => $result['signed_api_url'],
                'token' => $result['token'],
                'token_hash' => $invite->token_hash,
                'intended_for' => $invite->intended_for,
                'mobile_number' => $invite->mobile_number,
                'expires_at' => $invite->expires_at?->toIso8601String(),
                'status' => $invite->statusLabel(),
            ],
        ], 201);
    }

    /**
     * GET /api/v1/admin/registration-invites  (super admin)
     * Recent invites with status — the raw token/URL is never listed.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$this->invites->isEnabled()) {
            return response()->json(['data' => []]);
        }

        $invites = RegistrationInvite::query()
            ->with(['creator:user_id,first_name,last_name', 'usedBy:user_id,first_name,last_name'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (RegistrationInvite $invite) => [
                'id' => $invite->id,
                'intended_for' => $invite->intended_for,
                'mobile_number' => $invite->mobile_number,
                'token_hash_preview' => substr((string) $invite->token_hash, 0, 12) . '…',
                'status' => $invite->statusLabel(),
                'expires_at' => $invite->expires_at?->toIso8601String(),
                'used_at' => $invite->used_at?->toIso8601String(),
                'used_by' => $invite->usedBy
                    ? trim($invite->usedBy->first_name . ' ' . $invite->usedBy->last_name)
                    : null,
                'created_by' => $invite->creator
                    ? trim($invite->creator->first_name . ' ' . $invite->creator->last_name)
                    : null,
                'created_at' => $invite->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $invites]);
    }

    /**
     * PATCH /api/v1/admin/registration-invites/{id}/revoke  (super admin)
     * Soft invalidation — the row is kept as evidence (archive-not-delete).
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        if (!$this->invites->isEnabled()) {
            return response()->json(['message' => 'Registration invites are not available yet.'], 503);
        }

        $invite = RegistrationInvite::findOrFail($id);

        if ($invite->isUsed()) {
            return response()->json([
                'message' => 'This link was already used to register — it cannot be revoked, only reviewed.',
            ], 409);
        }

        $this->invites->revoke($invite);

        $this->audit->log(
            $request,
            'registration_invite_revoked',
            'registration',
            $invite,
            [],
            ['revoked_at' => $invite->revoked_at?->toIso8601String()],
            [],
            'warning',
            $invite->intended_for ?: ('Invite #' . $invite->id)
        );

        return response()->json([
            'message' => 'Registration link revoked.',
            'data' => ['id' => $invite->id, 'status' => $invite->statusLabel()],
        ]);
    }

    /**
     * GET /api/v1/admin/register/validate-invite   (public, throttled)
     *
     * The signed verification endpoint. The /register page replays its query
     * string here BEFORE showing the form. Distinct, honest errors per the
     * panelist requirement: invalid signature / expired / not found / revoked
     * / already used — never one collapsed "invalid link".
     */
    public function verify(Request $request): JsonResponse
    {
        if (!$this->invites->isEnabled()) {
            // Table not migrated yet — legacy open registration still applies.
            return response()->json([
                'valid' => true,
                'code' => 'legacy_open',
                'message' => 'Registration link checks are not enabled yet.',
            ]);
        }

        $result = $this->invites->validateParams($request->only(['token', 'expires', 'signature']));

        return response()->json([
            'valid' => $result['ok'],
            'code' => $result['code'],
            'message' => $result['message'],
            'data' => $result['ok'] ? [
                'intended_for' => $result['invite']?->intended_for,
                'mobile_number' => $result['invite']?->mobile_number,
                'expires_at' => $result['invite']?->expires_at?->toIso8601String(),
            ] : null,
        ], $result['ok'] ? 200 : $result['status']);
    }
}
