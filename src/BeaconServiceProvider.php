<?php

namespace WatchTowerX\BeaconX;

use Illuminate\Support\ServiceProvider;

class BeaconServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/beacon.php' => config_path('beacon.php'),
            ], 'beacon-config');

            $this->commands([
                Commands\TransmitMetrics::class,
            ]);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/beacon.php', 'beacon');
    }
}
