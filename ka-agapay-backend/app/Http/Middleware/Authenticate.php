<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * For API-only backend:
     * Do not redirect unauthenticated users to route('login'),
     * because this Laravel project does not use a web login route.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}