<?php
// app/Http/Controllers/Api/SmsController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Notification\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    public function __construct(
        private readonly SmsService $sms
    ) {}

    public function logs(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        $request->validate([
            'search'   => ['nullable', 'string', 'max:100'],
            'status'   => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $logs = SmsLog::with('user')
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim((string) $request->search);

                $q->where(function ($inner) use ($search) {
                    $inner->where('mobile_number', 'ilike', "%{$search}%")
                        ->orWhere('message', 'ilike', "%{$search}%")
                        ->orWhere('notification_type', 'ilike', "%{$search}%");
                });
            })
            ->latest('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($logs);
    }

    public function send(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        $validated = $request->validate([
            'mode'          => ['required', 'in:single,barangay,all'],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'to'            => ['nullable', 'string', 'max:20'],
            'phone'         => ['nullable', 'string', 'max:20'],
            'barangay'      => ['nullable', 'string', 'max:150'],
            'message'       => ['required', 'string', 'min:3', 'max:480'],
        ]);

        $mode = $validated['mode'];
        $message = $validated['message'];

        if ($mode === 'single') {
            $mobile = $validated['mobile_number']
                ?? $validated['to']
                ?? $validated['phone']
                ?? null;

            abort_unless($mobile, 422, 'Mobile number is required.');

            $log = $this->sms->send(
                $mobile,
                $message,
                'manual_admin_sms',
                $request->user()->user_id ?? $request->user()->id
            );

            return response()->json([
                'message' => 'SMS sent.',
                'data' => $log,
            ]);
        }

        $query = User::query()
            ->whereNotNull('mobile_number')
            ->where('mobile_number', '!=', '');

        if ($mode === 'barangay') {
            abort_unless(!empty($validated['barangay']), 422, 'Barangay is required.');

            $query->where('barangay', 'ilike', '%' . trim($validated['barangay']) . '%');
        }

        $users = $query->limit(300)->get();

        $sent = [];
        foreach ($users as $user) {
            $sent[] = $this->sms->send(
                $user->mobile_number,
                $message,
                $mode === 'barangay' ? 'manual_barangay_sms' : 'manual_all_sms',
                $user->user_id
            );
        }

        return response()->json([
            'message' => count($sent) . ' SMS request(s) processed.',
            'data' => $sent,
        ]);
    }

    private function authorizeSms(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole(['mho', 'super_admin', 'admin', 'rhu_admin', 'staff_admin', 'staff', 'nurse', 'midwife']),
            403,
            'You are not allowed to use the SMS module.'
        );
    }
}