<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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
        $resolveEmailKey = static function (Request $request): string {
            $emailInput = $request->input('email');

            if (!is_string($emailInput)) {
                return 'guest';
            }

            $email = strtolower(trim($emailInput));
            return $email !== '' ? $email : 'guest';
        };

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth-login', function (Request $request) use ($resolveEmailKey) {
            $email = $resolveEmailKey($request);

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('auth-reset', function (Request $request) use ($resolveEmailKey) {
            $email = $resolveEmailKey($request);

            return Limit::perMinute(3)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('auth-otp', function (Request $request) use ($resolveEmailKey) {
            $email = $resolveEmailKey($request);

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
