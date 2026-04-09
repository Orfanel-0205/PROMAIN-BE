<?php

namespace App\Providers;

use App\Models\QueueTicket;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Policies\QueueTicketPolicy;
use App\Policies\TelemedicinePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        QueueTicket::class         => QueueTicketPolicy::class,
        TelemedicineRequest::class => TelemedicinePolicy::class,
        TelemedicineSession::class => TelemedicinePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}