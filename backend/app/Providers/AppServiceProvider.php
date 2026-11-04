<?php

namespace App\Providers;

use App\Access\Permissions;
use App\Ledger\Events\EventBus;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventBus::class, fn ($app) => new EventBus(
            ...array_map(fn (string $class) => $app->make($class), config('ledger.consumers', []))
        ));
    }

    public function boot(): void
    {
        // One gate per permission. A deactivated user is denied everything.
        foreach (Permissions::all() as $permission) {
            Gate::define($permission, fn (User $user) => $user->active
                && in_array($permission, Permissions::for($user->role), true));
        }

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('username')) . '|' . $request->ip()),
            Limit::perMinute(30)->by((string) $request->ip()),
        ]);
    }
}