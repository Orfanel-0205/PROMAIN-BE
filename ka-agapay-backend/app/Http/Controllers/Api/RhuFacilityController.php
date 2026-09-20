<?php
// app/Http/Controllers/Api/RhuFacilityController.php
//
// Managing the Rural Health Units themselves: the screen behind
// Administration → RHU Facilities.
//
// Any signed-in staff member can READ the list, because every RHU picker in
// the dashboard and the mobile app is built from it. Only a super admin can
// add a facility, rename one, switch it off, or change which barangays it
// serves — that decision reshapes who sees which patients.
//
// A facility is never deleted. Queue tickets, appointments, prescriptions and
// stock all carry its id, and deleting the row would leave those records
// pointing at nothing. Switching it off removes it from every picker while
// its history stays readable.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RhuFacility;
use App\Services\Audit\AuditActions;
use App\Services\Audit\AuditService;
use App\Support\Rhu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class RhuFacilityController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * GET /rhus
     *
     * Active facilities, plus the switched-off ones for a super admin, who is
     * the only person who can bring them back.
     */
    public function index(Request $request): JsonResponse
    {
        $includeInactive = $request->user()?->hasAnyRole(['super_admin', 'superadmin']) ?? false;

        $facilities = RhuFacility::query()
            ->when(!$includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get()
            ->map(fn (RhuFacility $rhu) => $this->present($rhu));

        return response()->json(['data' => $facilities]);
    }

    /** POST /rhus — open a new facility. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('rhus', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'short_name' => ['required', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:40'],
        ], [
            'code.regex' => 'The code may use letters, numbers, dashes and underscores only.',
        ]);

        $facility = RhuFacility::create($validated + ['is_active' => true]);

        Rhu::flushCache();

        $this->audit->info(AuditActions::RHU_CREATED, 'rhu', [
            'subject_type' => 'rhu',
            'subject_id' => $facility->id,
            'subject_label' => $facility->name,
            'new_values' => $validated,
        ]);

        return response()->json([
            'message' => "{$facility->short_name} is now available across the system.",
            'data' => $this->present($facility),
        ], 201);
    }

    /** PUT /rhus/{id} — rename a facility, or switch it on or off. */
    public function update(Request $request, int $id): JsonResponse
    {
        $facility = RhuFacility::findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('rhus', 'code')->ignore($facility->id)],
            'name' => ['sometimes', 'string', 'max:150'],
            'short_name' => ['sometimes', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Never leave the municipality with no facility at all: something has
        // to answer for unassigned barangays and legacy records.
        if (array_key_exists('is_active', $validated) && !$validated['is_active']) {
            $remaining = RhuFacility::where('is_active', true)->where('id', '!=', $facility->id)->count();

            abort_if($remaining === 0, 422, 'At least one RHU must stay active.');
        }

        $before = $facility->only(['code', 'name', 'short_name', 'address', 'contact_number', 'is_active']);

        $facility->update($validated);

        Rhu::flushCache();

        $this->audit->info(AuditActions::RHU_UPDATED, 'rhu', [
            'subject_type' => 'rhu',
            'subject_id' => $facility->id,
            'subject_label' => $facility->name,
            'old_values' => $before,
            'new_values' => $validated,
        ]);

        return response()->json([
            'message' => "{$facility->short_name} updated.",
            'data' => $this->present($facility),
        ]);
    }

    /**
     * PUT /rhus/{id}/barangays — which barangays this facility serves.
     *
     * This is what actually routes residents: a resident's barangay decides
     * their RHU, so opening a new facility means moving barangays to it.
     */
    public function assignBarangays(Request $request, int $id): JsonResponse
    {
        $facility = RhuFacility::findOrFail($id);

        abort_unless(
            Schema::hasTable('barangays') && Schema::hasColumn('barangays', 'rhu_id'),
            422,
            'This installation has no barangay-to-RHU mapping yet.'
        );

        $validated = $request->validate([
            'barangay_ids' => ['present', 'array'],
            'barangay_ids.*' => ['integer', Rule::exists('barangays', 'barangay_id')],
        ]);

        $ids = array_map('intval', $validated['barangay_ids']);

        DB::transaction(function () use ($facility, $ids) {
            // Barangays dropped from the list fall back to the default
            // facility rather than being left pointing at nothing.
            DB::table('barangays')
                ->where('rhu_id', $facility->id)
                ->whereNotIn('barangay_id', $ids === [] ? [0] : $ids)
                ->update(['rhu_id' => Rhu::defaultId()]);

            if ($ids !== []) {
                DB::table('barangays')->whereIn('barangay_id', $ids)->update(['rhu_id' => $facility->id]);
            }
        });

        // The barangay list is cached for a day and carries rhu_id, so without
        // this the app and the dashboard would route residents by the old map.
        Cache::forget('barangays_list_v2');

        $this->audit->info(AuditActions::RHU_UPDATED, 'rhu', [
            'subject_type' => 'rhu',
            'subject_id' => $facility->id,
            'subject_label' => $facility->name,
            'new_values' => ['barangay_count' => count($ids)],
        ]);

        return response()->json([
            'message' => count($ids) . " barangay(s) now served by {$facility->short_name}.",
            'data' => $this->present($facility->fresh()),
        ]);
    }

    /**
     * The shape every RHU picker uses, including how many barangays and staff
     * a facility has — the two numbers that say whether it is really in use.
     *
     * @return array<string, mixed>
     */
    private function present(RhuFacility $facility): array
    {
        $barangayIds = Schema::hasColumn('barangays', 'rhu_id')
            ? DB::table('barangays')->where('rhu_id', $facility->id)->pluck('barangay_id')->all()
            : [];

        return [
            'id' => $facility->id,
            'code' => $facility->code,
            'name' => $facility->name,
            'short_name' => $facility->short_name,
            'address' => $facility->address,
            'contact_number' => $facility->contact_number,
            'is_active' => $facility->is_active,
            'barangay_ids' => array_map('intval', $barangayIds),
            'barangay_count' => count($barangayIds),
            'staff_count' => Schema::hasColumn('users', 'assigned_rhu_id')
                ? DB::table('users')->where('assigned_rhu_id', $facility->id)->count()
                : 0,
        ];
    }
}
