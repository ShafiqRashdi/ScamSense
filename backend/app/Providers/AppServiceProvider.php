<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Detection\VerifiedDomainRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Without this binding the container resolves VerifiedDomainRegistry
         * through its default constructor, which takes empty arrays — so the
         * verified-domain gate would silently never fire. It would look
         * configured, the config file would be full of correct domains, and
         * every genuine bank URL would still be flagged.
         *
         * A singleton because the lists are immutable for the request's
         * lifetime and rebuilding them per resolution is pointless work.
         */
        $this->app->singleton(
            VerifiedDomainRegistry::class,
            static fn (): VerifiedDomainRegistry => VerifiedDomainRegistry::fromConfig(),
        );
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * Public rate limits from AGENTS.md §3.
     *
     * Named limiters rather than bare `throttle:n,m` middleware, because two
     * unnamed throttles on the same route resolve to the same cache key — the
     * signature is derived from the route and IP, not from the limit — so they
     * share a counter and each request increments it twice. Stacking a
     * per-minute and a per-hour limit that way silently throttles at a fraction
     * of the intended rate. A named limiter puts its own name in the key.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('verify-minute', fn (Request $request): Limit => Limit::perMinute(
            (int) config('scamsense.rate_limits.verify_per_minute')
        )->by($request->ip()));

        RateLimiter::for('verify-hour', fn (Request $request): Limit => Limit::perHour(
            (int) config('scamsense.rate_limits.verify_per_hour')
        )->by($request->ip()));

        // Tighter per-minute than verification: there is no legitimate reason
        // to leave feedback in a burst.
        RateLimiter::for('feedback-minute', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($request->ip()));

        RateLimiter::for('feedback-hour', fn (Request $request): Limit => Limit::perHour(
            (int) config('scamsense.rate_limits.feedback_per_hour')
        )->by($request->ip()));
    }
}
