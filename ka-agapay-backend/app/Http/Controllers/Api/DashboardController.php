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
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    /**
     * GET /api/v1/dashboard
     * Mobile patient dashboard.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $profileId = $user->residentProfile?->id;

        // Active queue ticket
        $queueTicket = null;

        try {
            if ($profileId && Schema::hasTable('queue_tickets')) {
                $queueTicket = QueueTicket::where('resident_profile_id', $profileId)
                    ->whereIn('status', ['waiting', 'called', 'in_service', 'serving'])
                    ->withoutTrashed()
                    ->latest()
                    ->first();
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard: queue ticket query skipped', [
                'reason' => $e->getMessage(),
            ]);
        }

        // Next upcoming appointment
        $nextAppointment = null;

        try {
            if (
                class_exists(\App\Models\Appointment::class) &&
                Schema::hasTable('appointments')
            ) {
                $nextAppointment = \App\Models\Appointment::where('user_id', $user->user_id)
                    ->whereDate('appointment_date', '>=', today())
                    ->whereNotIn('status', ['cancelled', 'completed', 'rejected'])
                    ->orderBy('appointment_date')
                    ->orderBy('appointment_time')
                    ->first();
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard: appointment query skipped', [
                'reason' => $e->getMessage(),
            ]);
        }

        // Unread notifications
        $unreadCount = 0;

        try {
            $unreadCount = $user->unreadNotifications()->count();
        } catch (\Throwable $e) {
            Log::warning('Dashboard: notifications query skipped', [
                'reason' => $e->getMessage(),
            ]);
        }

        // Last telemedicine session summary
        $lastSession = null;

        try {
            if ($profileId && Schema::hasTable('telemedicine_sessions')) {
                $lastSession = TelemedicineSession::whereHas(
                    'request',
                    fn ($q) => $q->where('resident_profile_id', $profileId)
                )
                    ->whereIn('status', ['ended', 'completed'])
                    ->latest()
                    ->first();
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard: telemedicine session query skipped', [
                'reason' => $e->getMessage(),
            ]);
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
                    'specialty'    => $nextAppointment->specialty ?? 'General Medicine',
                    'scheduled_at' => $this->formatAppointmentSchedule($nextAppointment),
                    'status'       => $nextAppointment->status ?? null,
                    'type'         => $nextAppointment->consultation_type ?? null,
                ] : null,

                'unread_notifications'      => $unreadCount,
                'last_consultation_summary' => $lastSession?->ai_summary ?? null,
            ],
        ]);
    }

    /**
     * GET /api/v1/dashboard/admin
     * Admin dashboard.
     */
    public function admin(Request $request)
    {
        $today = Carbon::today();

        $queueWaiting = 0;
        $avgWait = 0;

        try {
            if (Schema::hasTable('queue_tickets')) {
                $queueWaiting = QueueTicket::whereDate('created_at', $today)
                    ->whereIn('status', ['waiting', 'called', 'in_service'])
                    ->count();

                if (
                    Schema::hasColumn('queue_tickets', 'service_started_at') &&
                    Schema::hasColumn('queue_tickets', 'issued_at')
                ) {
                    $avgWait = QueueTicket::whereDate('created_at', $today)
                        ->whereNotNull('service_started_at')
                        ->whereNotNull('issued_at')
                        ->selectRaw('AVG(EXTRACT(EPOCH FROM (service_started_at - issued_at)) / 60) as avg_mins')
                        ->value('avg_mins') ?? 0;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard admin: queue stats skipped', [
                'reason' => $e->getMessage(),
            ]);
        }

        $tmTotal = 0;
        $tmPending = 0;
        $tmEmergency = 0;

        try {
            if (Schema::hasTable('telemedicine_requests')) {
                $tmTotal = TelemedicineRequest::whereDate('created_at', $today)->count();

                $tmPending = TelemedicineRequest::whereDate('created_at', $today)
                    ->where('status', 'pending')
                    ->count();

                $tmEmergency = TelemedicineRequest::whereDate('created_at', $today)
                    ->where('urgency_level', 'emergency')
                    ->count();
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard admin: telemedicine stats skipped', [
                'reason' => $e->getMessage(),
            ]);
        }

        $rxTotal = 0;
        $rxDispensed = 0;

        try {
            if (Schema::hasTable('prescriptions')) {
                $rxTotal = Prescription::whereDate('created_at', $today)->count();

                $rxDispensed = Prescription::whereDate('created_at', $today)
                    ->where('status', 'dispensed')
                    ->count();
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard admin: prescription stats skipped', [
                'reason' => $e->getMessage(),
            ]);
        }

        $refPending = 0;
        $refUrgent = 0;

        try {
            if (Schema::hasTable('referrals')) {
                $refPending = Referral::whereDate('created_at', $today)
                    ->where('status', 'pending')
                    ->count();

                $refUrgent = Referral::whereDate('created_at', $today)
                    ->where('priority', 'urgent')
                    ->count();
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard admin: referral stats skipped', [
                'reason' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data' => [
                'today' => [
                    'queue' => [
                        'waiting' => $queueWaiting,
                        'avg_wait_minutes' => round((float) $avgWait, 1),
                    ],
                    'telemedicine' => [
                        'total' => $tmTotal,
                        'pending' => $tmPending,
                        'emergency' => $tmEmergency,
                    ],
                    'prescriptions' => [
                        'total_issued' => $rxTotal,
                        'dispensed' => $rxDispensed,
                    ],
                    'referrals' => [
                        'pending' => $refPending,
                        'urgent' => $refUrgent,
                    ],
                ],
            ],
        ]);
    }

    private function getQueuePosition(QueueTicket $ticket): int
    {
        try {
            return QueueTicket::whereDate('created_at', Carbon::today())
                ->where('rhu_id', $ticket->rhu_id)
                ->where('service_type', $ticket->service_type)
                ->whereIn('status', ['waiting', 'called', 'in_service'])
                ->where('id', '<=', $ticket->id)
                ->withoutTrashed()
                ->count();
        } catch (\Throwable $e) {
            Log::warning('Dashboard: queue position failed', [
                'reason' => $e->getMessage(),
            ]);

            return 1;
        }
    }

    private function estimateWait(QueueTicket $ticket): int
    {
        /*
         * IMPORTANT:
         * queue_tickets table does NOT have completed_at.
         * It has service_ended_at.
         */

        try {
            if (
                !Schema::hasColumn('queue_tickets', 'service_started_at') ||
                !Schema::hasColumn('queue_tickets', 'service_ended_at')
            ) {
                return 5;
            }

            $avg = QueueTicket::whereDate('created_at', Carbon::today())
                ->where('rhu_id', $ticket->rhu_id)
                ->where('service_type', $ticket->service_type)
                ->whereNotNull('service_started_at')
                ->whereNotNull('service_ended_at')
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (service_ended_at - service_started_at)) / 60) as avg_minutes')
                ->value('avg_minutes');

            $avgMinutes = (float) ($avg ?? 5);

            if ($avgMinutes <= 0) {
                $avgMinutes = 5;
            }

            return (int) round($this->getQueuePosition($ticket) * $avgMinutes);
        } catch (\Throwable $e) {
            Log::warning('Dashboard: estimate wait failed', [
                'reason' => $e->getMessage(),
            ]);

            return 5;
        }
    }

    private function formatAppointmentSchedule($appointment): ?string
    {
        if (!$appointment?->appointment_date) {
            return null;
        }

        $date = optional($appointment->appointment_date)->format('Y-m-d')
            ?? (string) $appointment->appointment_date;

        $time = $appointment->appointment_time
            ? substr((string) $appointment->appointment_time, 0, 5)
            : null;

        return $time ? "{$date} {$time}" : $date;
    }
}