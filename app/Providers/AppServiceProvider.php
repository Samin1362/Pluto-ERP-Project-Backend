<?php

namespace App\Providers;

use App\Models\PurchaseBill;
use App\Support\CurrentCompany;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        // Document morph aliases are their table names (journal_entries.source_type, stock_movements.source_type;
        // BooksIntegrity relies on it). Models not listed keep their class name.
        Relation::morphMap(['purchase_bills' => PurchaseBill::class]);

        // The `api` guard reads the user that AuthenticateJwt verified; it never accepts a session cookie.
        Auth::viaRequest('pluto-jwt', fn (Request $request) => $request->attributes->get('auth_user'));

        // 05 §1 rate limits: auth endpoints 20/min per IP; authenticated calls 120/min per user.
        RateLimiter::for('auth-ip', fn (Request $r) => Limit::perMinute((int) config('pluto.rate_limits.auth_per_ip'))->by('auth:'.$r->ip()));
        RateLimiter::for('api-user', fn (Request $r) => Limit::perMinute((int) config('pluto.rate_limits.api_per_user'))->by('user:'.($r->attributes->get('auth_user')?->id ?? $r->ip())));
    }
}
