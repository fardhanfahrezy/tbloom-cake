<?php

namespace App\Providers;

use App\CanonicalContact;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('login', function (Request $request): array {
            $email = is_string($request->input('email')) ? CanonicalContact::email($request->input('email')) : '';

            return [
                Limit::perMinute(5)->by('identity:'.hash('sha256', $email.'|'.$request->ip())),
                Limit::perMinute(30)->by('ip:'.$request->ip()),
            ];
        });
    }
}
