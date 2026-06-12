<?php
//app/Http/Controllers/Api/ApprovalController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RegistrationApproval;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $approvals = RegistrationApproval::with([
                'user.residentProfile',
                'user.verificationDocument.ocrResult',
                'reviewer',
            ])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json($approvals);
    }

    public function pending(): JsonResponse
    {
        $approvals = RegistrationApproval::with([
                'user.residentProfile',
                'user.verificationDocument.ocrResult',
            ])
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json(['data' => $approvals, 'count' => $approvals->count()]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $approval = RegistrationApproval::with('user')->findOrFail($id);

        $request->validate([
            'review_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $approval->update([
            'status'      => 'approved',
            'reviewed_by' => $request->user()->user_id,
            'review_notes'=> $request->review_notes,
            'reviewed_at' => now(),
        ]);

        $approval->user->update(['account_status' => 'active']);

        // Notify resident (Notification logic placeholder)
        // $approval->user->notify(new \App\Notifications\RegistrationApprovedNotification());

        $this->audit->info('registration.approved', 'approvals', [
            'subject_id'    => $approval->user_id,
            'subject_label' => $approval->user->first_name . ' ' . $approval->user->last_name,
        ]);

        return response()->json(['message' => 'Registration approved. Account is now active.']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $approval = RegistrationApproval::with('user')->findOrFail($id);

        $request->validate([
            'rejection_reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $approval->update([
            'status'           => 'rejected',
            'reviewed_by'      => $request->user()->user_id,
            'rejection_reason' => $request->rejection_reason,
            'reviewed_at'      => now(),
        ]);

        $approval->user->update(['account_status' => 'rejected']);

        // Notification logic placeholder
        // $approval->user->notify(new \App\Notifications\RegistrationRejectedNotification($request->rejection_reason));

        $this->audit->warning('registration.rejected', 'approvals', [
            'subject_id'    => $approval->user_id,
            'subject_label' => $approval->user->first_name . ' ' . $approval->user->last_name,
            'metadata'      => ['reason' => $request->rejection_reason],
        ]);

        return response()->json(['message' => 'Registration rejected.']);
    }
}