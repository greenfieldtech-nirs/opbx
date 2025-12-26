<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register service singletons
        $this->app->singleton(
            \App\Services\CloudonixClient\CloudonixClient::class
        );

        $this->app->singleton(
            \App\Services\CallStateManager\CallStateManager::class
        );

        $this->app->singleton(
            \App\Services\CallRouting\CallRoutingService::class
        );

        $this->app->singleton(
            \App\Services\CloudonixApiClient::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model policies
        Gate::policy(\App\Models\Extension::class, \App\Policies\ExtensionPolicy::class);
        Gate::policy(\App\Models\User::class, \App\Policies\UserPolicy::class);
        Gate::policy(\App\Models\ConferenceRoom::class, \App\Policies\ConferenceRoomPolicy::class);
        Gate::policy(\App\Models\CloudonixSettings::class, \App\Policies\CloudonixSettingsPolicy::class);

        // Disable model events for CLI commands if needed
        if ($this->app->runningInConsole()) {
            // Add any console-specific bootstrapping here
        }
    }
}
