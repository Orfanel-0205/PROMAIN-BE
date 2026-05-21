<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Audit\AuditService;
use App\Services\Prescription\PrescriptionService;
use App\Services\Referral\ReferralService;
use Illuminate\Support\Facades\Log;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mask mobile numbers and passwords from appearing in application logs
        Log::withContext([
            'app' => 'ka-agapay',
        ]);
    }
}
