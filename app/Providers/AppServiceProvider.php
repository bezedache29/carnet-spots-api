<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

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
        Password::defaults(fn () => Password::min(12)->uncompromised());

        RateLimiter::for('login', function ($request) {
            $key = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // apiResource update routes accept both PUT and PATCH on the same
        // route; Scramble documents only the first method by default. List
        // every non-HEAD method so both verbs show up in the generated docs.
        Scramble::configure()->resolveOperationMethodsUsing(
            fn (Route $route): array => array_values(array_diff(
                array_map('strtolower', $route->methods()),
                ['head']
            ))
        );
    }
}
