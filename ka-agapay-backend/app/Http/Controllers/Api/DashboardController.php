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
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /**
     * GET /v1/dashboard
     * Every section is wrapped in try/catch so one broken table
     * never takes down the entire dashboard response.
     */
    public function index(Request $request)
    {
        $user      = $request->user();
        $profileId = $user->residentProfile?->id;

        // ── Active queue ticket ────────────────────────────────────────────────
        $queueTicket = null;
        try {
            if ($profileId) {
                $queueTicket = QueueTicket::where('resident_profile_id', $profileId)
                    ->whereIn('status', ['waiting', 'called', 'serving'])
                    ->withoutTrashed()
                    ->latest()
                    ->first();
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard: queue ticket query skipped', ['reason' => $e->getMessage()]);
        }

        // ── Next upcoming appointment ──────────────────────────────────────────
        $nextAppointment = null;
        try {
            if (class_exists(\App\Models\Appointment::class)) {
                $nextAppointment = \App\Models\Appointment::where('user_id', $user->user_id)
                    ->where('appointment_date', '>', now())
                    ->whereNotIn('status', ['cancelled', 'completed'])
                    ->orderBy('appointment_date')
                    ->first();
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard: appointment query skipped', ['reason' => $e->getMessage()]);
        }

        // ── Unread notifications ───────────────────────────────────────────────
        $unreadCount = 0;
        try {
            $unreadCount = $user->unreadNotifications()->count();
        } catch (\Throwable $e) {
            Log::warning('Dashboard: notifications query skipped', ['reason' => $e->getMessage()]);
        }

        // ── Last telemedicine session summary ──────────────────────────────────
        // FIX: telemedicine_requests has no user_id — link through resident_profile_id
        $lastSession = null;
        try {
            if ($profileId) {
                $lastSession = TelemedicineSession::whereHas(
                    'request',
                    fn($q) => $q->where('resident_profile_id', $profileId)
                )
                    ->where('status', 'completed')
                    ->latest()
                    ->first();
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard: telemedicine session query skipped', ['reason' => $e->getMessage()]);
        }

        return response()->json([
            'data' => [
                'queue' => $queueTicket ? [
                    'ticket_number'          => $queueTicket->ticket_number,
                    'position'               => $this->getQueuePosition($queueTicket),
                    'estimated_wait_minutes' => $this->estimateWait($queueTicket),
                    'status'                 => $queueTicket->status,
                ] : [
                    'ticket_number'          => null,
                    'position'               => null,
                    'estimated_wait_minutes' => null,
                    'status'                 => null,
                ],

                'upcoming_appointment' => $nextAppointment ? [
                    'id'           => $nextAppointment->id,
                    'doctor_name'  => $nextAppointment->doctor_name ?? 'RHU Doctor',
                    'specialty'    => $nextAppointment->specialty   ?? 'General Medicine',
                    'scheduled_at' => $nextAppointment->appointment_date ?? null,
                ] : null,

                'unread_notifications'      => $unreadCount,
                'last_consultation_summary' => $lastSession?->ai_summary ?? null,
            ],
        ]);
    }

    /**
     * GET /v1/dashboard/admin
     */
    public function admin(Request $request)
    {
        $today = Carbon::today();

        $queueWaiting = QueueTicket::whereDate('created_at', $today)
            ->whereIn('status', ['waiting', 'called'])
            ->count();

        $avgWait = QueueTicket::whereDate('created_at', $today)
            ->whereNotNull('service_started_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (service_started_at - issued_at))/60) as avg_mins')
            ->value('avg_mins');

        $tmTotal     = TelemedicineRequest::whereDate('created_at', $today)->count();
        $tmPending   = TelemedicineRequest::whereDate('created_at', $today)->where('status', 'pending')->count();
        $tmEmergency = TelemedicineRequest::whereDate('created_at', $today)->where('urgency_level', 'emergency')->count();

        $rxTotal     = Prescription::whereDate('created_at', $today)->count();
        $rxDispensed = Prescription::whereDate('created_at', $today)->where('status', 'dispensed')->count();

        $refPending = Referral::whereDate('created_at', $today)->where('status', 'pending')->count();
        $refUrgent  = Referral::whereDate('created_at', $today)->where('priority', 'urgent')->count();

        return response()->json([
            'data' => [
                'today' => [
                    'queue'         => ['waiting' => $queueWaiting, 'avg_wait_minutes' => round($avgWait ?? 0, 1)],
                    'telemedicine'  => ['total' => $tmTotal, 'pending' => $tmPending, 'emergency' => $tmEmergency],
                    'prescriptions' => ['total_issued' => $rxTotal, 'dispensed' => $rxDispensed],
                    'referrals'     => ['pending' => $refPending, 'urgent' => $refUrgent],
                ],
            ],
        ]);
    }

    private function getQueuePosition(QueueTicket $ticket): int
    {
        return QueueTicket::whereDate('created_at', Carbon::today())
            ->whereIn('status', ['waiting', 'called'])
            ->where('id', '<', $ticket->id)
            ->count() + 1;
    }

    private function estimateWait(QueueTicket $ticket): int
    {
        $avg = QueueTicket::whereDate('created_at', Carbon::today())
            ->whereNotNull('service_started_at')
            ->whereNotNull('completed_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (completed_at - service_started_at))/60) as avg')
            ->value('avg') ?? 5;

        return (int) round($this->getQueuePosition($ticket) * $avg);
    }
}