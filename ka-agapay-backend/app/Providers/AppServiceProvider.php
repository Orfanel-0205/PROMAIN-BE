<?php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

use App\Services\Audit\AuditService;
use App\Services\Prescription\PrescriptionService;
use App\Services\Referral\ReferralService;

use App\Services\Analytics\HeatmapAnalyticsService;
use App\Services\Queue\QueuePrioritizationService;
use App\Services\Analytics\HeatmapAlertService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AuditService::class);
        $this->app->singleton(PrescriptionService::class);
        $this->app->singleton(ReferralService::class);

        // Analytics services
        $this->app->singleton(HeatmapAnalyticsService::class);
        $this->app->singleton(QueuePrioritizationService::class);
        $this->app->singleton(HeatmapAlertService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Leave the call handshake exactly as the browser wrote it.
         *
         * Laravel tidies every string that arrives in a request, which is
         * right for a name typed into a form and wrong for a session
         * description. An SDP is a protocol document whose every line,
         * including the last, must end in a carriage return and newline.
         * Trimming took that final pair off, and the browser at the other
         * end could not parse what it was handed -- it received the offer,
         * failed to read it, and never answered. Every call died there,
         * with a message about networks that had nothing to do with it.
         */
        $isCallHandshake = fn (Request $request) => $request->is('api/*/team-chat/calls/*/signal');

        TrimStrings::skipWhen($isCallHandshake);
        ConvertEmptyStringsToNull::skipWhen($isCallHandshake);

        /*
        |--------------------------------------------------------------------------
        | Model Observers for Ka-Agapay Notifications
        |--------------------------------------------------------------------------
        | These observers automatically create notification rows when:
        | - appointment requests are created from mobile
        | - appointment status changes
        | - telemedicine requests/sessions change
        | - queue tickets are issued/called
        | - events/programs are published
        | - announcements are published
        |--------------------------------------------------------------------------
        */

        \App\Models\Appointment::observe(
            \App\Observers\AppointmentObserver::class
        );

        \App\Models\TelemedicineRequest::observe(
            \App\Observers\TelemedicineRequestObserver::class
        );

        \App\Models\TelemedicineSession::observe(
            \App\Observers\TelemedicineSessionObserver::class
        );

        \App\Models\QueueTicket::observe(
            \App\Observers\QueueTicketObserver::class
        );

        \App\Models\Event::observe(
            \App\Observers\EventObserver::class
        );

        \App\Models\Announcement::observe(
            \App\Observers\AnnouncementObserver::class
        );
    }
}