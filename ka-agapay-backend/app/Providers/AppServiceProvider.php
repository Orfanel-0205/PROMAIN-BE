<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

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
        // Global log context
        Log::withContext([
            'app' => 'ka-agapay',
        ]);
    }
}