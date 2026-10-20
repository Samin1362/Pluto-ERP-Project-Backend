<?php

namespace App\Providers;

use App\Support\CurrentCompany;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);
    }

    public function boot(): void
    {
        // The `api` guard reads the user that AuthenticateJwt verified; it never accepts a session cookie.
        Auth::viaRequest('ledgerflow-jwt', fn (Request $request) => $request->attributes->get('auth_user'));

        // 05 §1 rate limits: auth endpoints 20/min per IP; authenticated calls 120/min per user.
        RateLimiter::for('auth-ip', fn (Request $r) => Limit::perMinute((int) config('ledgerflow.rate_limits.auth_per_ip'))->by('auth:'.$r->ip()));
        RateLimiter::for('api-user', fn (Request $r) => Limit::perMinute((int) config('ledgerflow.rate_limits.api_per_user'))->by('user:'.($r->attributes->get('auth_user')?->id ?? $r->ip())));
    }
}
