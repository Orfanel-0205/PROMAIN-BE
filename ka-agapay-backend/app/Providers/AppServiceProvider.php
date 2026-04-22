<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AuditService::class);
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
