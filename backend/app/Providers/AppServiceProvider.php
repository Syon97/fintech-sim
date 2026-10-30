<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Ledger\Events\EventBus;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EventBus::class, fn ($app) => new EventBus(
            ...array_map(fn (string $class) => $app->make($class), config('ledger.consumers', []))
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
