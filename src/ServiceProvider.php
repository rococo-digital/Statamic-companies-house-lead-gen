<?php

namespace Rococo\ChLeadGen;

use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Rococo\ChLeadGen\Commands\RunLeadGeneration;
use Rococo\ChLeadGen\Http\Controllers\CP\DashboardController;
use Rococo\ChLeadGen\Http\Controllers\CP\SettingsController;

class ServiceProvider extends AddonServiceProvider
{
    protected $commands = [
        RunLeadGeneration::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'vendor/ch-lead-gen',
    ];

    public function bootAddon()
    {
        $this->commands([
            RunLeadGeneration::class,
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
        
        Nav::extend(function ($nav) {
            $nav->create('CH Lead Gen')
                ->section('Tools')
                ->url(cp_route('ch-lead-gen.index'))
                ->icon('hammer-wrench')
                ->can('view ch-lead-gen');
        });

        Permission::register('view ch-lead-gen')
            ->label('View CH Lead Gen')
            ->description('Allow viewing the CH Lead Gen dashboard');
    }
}