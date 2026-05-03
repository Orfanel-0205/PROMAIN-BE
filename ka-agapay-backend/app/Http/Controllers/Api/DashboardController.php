<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QueueTicket;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Models\Prescription;
use App\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * GET /v1/dashboard/admin
     * Returns today's operational overview for the RHU dashboard.
     */
    public function admin(Request $request)
    {
        $today = Carbon::today();

        // ── Queue ──────────────────────────────────────────────────────────────
        $queueWaiting = QueueTicket::whereDate('created_at', $today)
            ->whereIn('status', ['waiting', 'called'])
            ->count();

        $avgWait = QueueTicket::whereDate('created_at', $today)
            ->whereNotNull('service_started_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (service_started_at - issued_at))/60) as avg_mins')
            ->value('avg_mins');

        // ── Telemedicine ───────────────────────────────────────────────────────
        $tmTotal     = TelemedicineRequest::whereDate('created_at', $today)->count();
        $tmPending   = TelemedicineRequest::whereDate('created_at', $today)
            ->where('status', 'pending')
            ->count();
        $tmEmergency = TelemedicineRequest::whereDate('created_at', $today)
            ->where('urgency_level', 'emergency')
            ->count();

        // ── Prescriptions ──────────────────────────────────────────────────────
        $rxTotal     = Prescription::whereDate('created_at', $today)->count();
        $rxDispensed = Prescription::whereDate('created_at', $today)
            ->where('status', 'dispensed')
            ->count();

        // ── Referrals ──────────────────────────────────────────────────────────
        $refPending = Referral::whereDate('created_at', $today)
            ->where('status', 'pending')
            ->count();
        $refUrgent  = Referral::whereDate('created_at', $today)
            ->where('priority', 'urgent')
            ->count();

        return response()->json([
            'data' => [
                'today' => [
                    'queue' => [
                        'waiting'          => $queueWaiting,
                        'avg_wait_minutes' => round($avgWait ?? 0, 1),
                    ],
                    'telemedicine' => [
                        'total'     => $tmTotal,
                        'pending'   => $tmPending,
                        'emergency' => $tmEmergency,
                    ],
                    'prescriptions' => [
                        'total_issued' => $rxTotal,
                        'dispensed'    => $rxDispensed,
                    ],
                    'referrals' => [
                        'pending' => $refPending,
                        'urgent'  => $refUrgent,
                    ],
                ],
            ],
        ]);
    }
}
