<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Detection\VerifiedDomainRegistry;
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
        //
    }
}
