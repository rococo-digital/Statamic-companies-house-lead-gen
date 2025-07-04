<?php

namespace Rococo\ChLeadGen;

use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Illuminate\Support\Facades\Schedule;
use Rococo\ChLeadGen\Commands\RunLeadGeneration;
use Rococo\ChLeadGen\Commands\ManageRules;
use Rococo\ChLeadGen\Http\Controllers\CP\DashboardController;
use Rococo\ChLeadGen\Http\Controllers\CP\SettingsController;
use Rococo\ChLeadGen\Services\RuleManagerService;
use Rococo\ChLeadGen\Services\StatsService;
use Rococo\ChLeadGen\Services\CompaniesHouseService;
use Rococo\ChLeadGen\Services\ApolloService;
use Rococo\ChLeadGen\Services\InstantlyService;
use Rococo\ChLeadGen\Services\RuleConfigService;
use Rococo\ChLeadGen\Services\WebhookService;

class ServiceProvider extends AddonServiceProvider
{
    protected $commands = [
        RunLeadGeneration::class,
        ManageRules::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    // protected $vite = [
    //     'input' => [
    //         'resources/js/cp.js',
    //         'resources/css/cp.css',
    //     ],
    //     'publicDirectory' => 'vendor/ch-lead-gen',
    // ];

    public function bootAddon()
    {
        $this->commands([
            RunLeadGeneration::class,
            ManageRules::class,
        ]);

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ch-lead-gen');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'ch-lead-gen');
        $this->mergeConfigFrom(__DIR__.'/../config/ch-lead-gen.php', 'ch-lead-gen');

        $this->publishes([
            __DIR__.'/../config/ch-lead-gen.php' => config_path('ch-lead-gen.php'),
        ], 'ch-lead-gen-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/ch-lead-gen'),
        ], 'ch-lead-gen-views');

        // Remove problematic asset publishing for now
        // Assets will be handled by Vite instead
        
        // Register the scheduled command
        $this->registerScheduledCommands();
        
        Nav::extend(function ($nav) {
            $nav->create('CH Lead Gen')
                ->section('Tools')
                ->url(cp_route('ch-lead-gen.dashboard'))
                ->icon('hammer-wrench')
                ->can('view ch-lead-gen')
                ->children([
                    $nav->item('Dashboard')->url(cp_route('ch-lead-gen.dashboard')),
                    $nav->item('Rules')->url(cp_route('ch-lead-gen.rules.index')),
                    $nav->item('Settings')->url(cp_route('ch-lead-gen.settings')),
                ]);
        });

        Permission::register('view ch-lead-gen')
            ->label('View CH Lead Gen')
            ->description('Allow viewing the CH Lead Gen dashboard');
    }

    /**
     * Register scheduled commands for the addon
     */
    protected function registerScheduledCommands()
    {
        // Only register if the application is running in console or if we're in a scheduled context
        if ($this->app->runningInConsole() || $this->app->bound('schedule')) {
            Schedule::command('ch-lead-gen:run')
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground()
                ->description('Run Companies House lead generation scheduled rules');
        }
    }

    public function register()
    {
        // Register services with dependency injection
        $this->app->singleton(StatsService::class, function ($app) {
            return new StatsService();
        });

        $this->app->singleton(CompaniesHouseService::class, function ($app) {
            return new CompaniesHouseService();
        });

        $this->app->singleton(ApolloService::class, function ($app) {
            return new ApolloService();
        });

        $this->app->singleton(InstantlyService::class, function ($app) {
            return new InstantlyService();
        });

        $this->app->singleton(WebhookService::class, function ($app) {
            return new WebhookService();
        });

        $this->app->singleton(RuleManagerService::class, function ($app) {
            return new RuleManagerService(
                $app->make(CompaniesHouseService::class),
                $app->make(ApolloService::class),
                $app->make(InstantlyService::class),
                $app->make(StatsService::class),
                $app->make(WebhookService::class)
            );
        });

        $this->app->singleton(RuleConfigService::class, function ($app) {
            return new RuleConfigService();
        });
    }
}