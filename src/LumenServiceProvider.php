<?php

namespace EloquentFilter;

use EloquentFilter\Commands\MakeEloquentFilter;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

class LumenServiceProvider extends LaravelServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->commands(MakeEloquentFilter::class);
    }
}
