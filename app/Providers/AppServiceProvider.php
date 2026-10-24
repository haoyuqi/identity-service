<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        $this->configureLoginRateLimiting();
    }

    /**
     * Limit login attempts per account and per client address.
     *
     * The account key stops horizontal guessing against one address without
     * letting an attacker from a single address lock out every other account;
     * the address key bounds spraying across accounts from one origin.
     */
    private function configureLoginRateLimiting(): void
    {
        RateLimiter::for('login', static function (Request $request): array {
            $email = $request->input('email');
            $account = is_string($email) ? Str::lower(trim($email)) : '';
            $address = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('login-account:'.hash('sha256', $account.'|'.$address)),
                Limit::perMinute(25)->by('login-address:'.$address),
            ];
        });
    }
}
