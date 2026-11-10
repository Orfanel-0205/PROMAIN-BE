<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\User;
use App\Services\Audit\AuditActions;
use App\Services\Audit\AuditService;
use App\Support\LocalTime;
use App\Support\StaffSignatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * My Profile → My Signature, and the signature on a printed ITR.
 *
 * Only the MHO, doctors and the Super Admin sign (StaffSignatures::SIGNING_ROLES; the routes
 * enforce it). Saving or removing a signature asks for the account password
 * again, so nobody can put a signature on an unattended, signed-in computer.
 */
class StaffSignatureController extends Controller
{
    private const IMAGE_RULES = ['required', 'file', 'max:4096', 'mimetypes:image/png,image/jpeg,image/webp'];

    private const STRICTNESS_RULES = [
        'nullable',
        'integer',
        'min:' . StaffSignatures::MIN_STRICTNESS,
        'max:' . StaffSignatures::MAX_STRICTNESS,
    ];

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $row = StaffSignatures::current((int) $request->user()->user_id);

        return response()->json(['signature' => $this->payload($row)]);
    }

    /**
     * The signature extracted from an uploaded photo, shown before saving so
     * the person can see what will print and adjust the strictness. Nothing
     * is stored, so no password is asked.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => self::IMAGE_RULES,
            'strictness' => self::STRICTNESS_RULES,
        ]);

        try {
            $png = StaffSignatures::normalize(
                (string) file_get_contents($request->file('image')->getRealPath()),
                (int) ($validated['strictness'] ?? StaffSignatures::DEFAULT_STRICTNESS)
            );
        } catch (InvalidArgumentException $e) {
            return $this->unreadable($e);
        }

        return response()->json(['image' => 'data:image/png;base64,' . base64_encode($png)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'image' => self::IMAGE_RULES,
            'source' => ['required', Rule::in(['drawn', 'uploaded'])],
            'strictness' => self::STRICTNESS_RULES,
            'current_password' => ['required', 'string'],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return $this->wrongPassword();
        }

        try {
            $row = StaffSignatures::save(
                $user,
                (string) file_get_contents($request->file('image')->getRealPath()),
                $validated['source'],
                (int) ($validated['strictness'] ?? StaffSignatures::DEFAULT_STRICTNESS)
            );
        } catch (InvalidArgumentException $e) {
            return $this->unreadable($e);
        }

        $this->audit->info('signature', AuditActions::SIGNATURE_SAVED, [
            'signature_id' => $row->id,
            'source' => $row->source,
        ], $request);

        return response()->json([
            'message' => 'Signature saved. It will appear on prescriptions, lab requests and ITRs you issue from now on.',
            'signature' => $this->payload($row),
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return $this->wrongPassword();
        }

        if (StaffSignatures::revoke($user)) {
            $this->audit->info('signature', AuditActions::SIGNATURE_REMOVED, [], $request);
        }

        return response()->json([
            'message' => 'Signature removed. Documents you issue from now on will have a blank signature line.',
            'signature' => null,
        ]);
    }

    /**
     * The signature for a completed consultation's ITR: the doctor or MHO who
     * completed it, else the attending physician, as of the moment it was
     * completed. Null while the record is still open.
     */
    public function forConsultation(Request $request, int $id): JsonResponse
    {
        $consultation = Consultation::query()->findOrFail($id);

        if (strtolower((string) $consultation->status) !== 'completed' || !$consultation->completed_at) {
            return response()->json(['signature' => null]);
        }

        $candidates = array_unique(array_filter([
            (int) ($consultation->completed_by ?? 0),
            (int) ($consultation->attended_by ?? 0),
        ]));

        foreach ($candidates as $userId) {
            $image = StaffSignatures::forDocument($userId, $consultation->completed_at);

            if ($image) {
                return response()->json([
                    'signature' => [
                        'image' => $image,
                        'signer_id' => $userId,
                        'signer_name' => User::query()->find($userId)?->full_name,
                    ],
                ]);
            }
        }

        return response()->json(['signature' => null]);
    }

    private function payload(?object $row): ?array
    {
        if (!$row) {
            return null;
        }

        return [
            'image' => StaffSignatures::dataUri($row),
            'source' => $row->source,
            'saved_at' => LocalTime::iso($row->created_at),
        ];
    }

    private function unreadable(InvalidArgumentException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => ['image' => [$e->getMessage()]],
        ], 422);
    }

    private function wrongPassword(): JsonResponse
    {
        return response()->json([
            'message' => 'Current password is incorrect.',
            'errors' => ['current_password' => ['Current password is incorrect.']],
        ], 422);
    }
}
