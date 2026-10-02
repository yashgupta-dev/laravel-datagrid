<?php

namespace CodeCorner\Datagrid;

use CodeCorner\Datagrid\Auth\ActorResolver;
use CodeCorner\Datagrid\Auth\AuthManager;
use CodeCorner\Datagrid\Auth\GuardActorResolver;
use CodeCorner\Datagrid\Console\DoctorCommand;
use CodeCorner\Datagrid\Core\GridRegistry;
use CodeCorner\Datagrid\Core\Operators\OperatorRegistry;
use CodeCorner\Datagrid\Core\Query\QueryEngine;
use CodeCorner\Datagrid\View\Components\Datagrid;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class DatagridServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/datagrid.php', 'datagrid');

        $this->app->singleton(OperatorRegistry::class, fn () => new OperatorRegistry(
            (int) config('datagrid.limits.max_in_values', 500)
        ));

        $this->app->singleton(QueryEngine::class, fn ($app) => new QueryEngine($app->make(OperatorRegistry::class)));

        $this->app->singleton(AuthManager::class, fn ($app) => new AuthManager($app, config('datagrid.authorization', [])));

        $this->app->singleton(ActorResolver::class, function ($app) {
            $custom = config('datagrid.authorization.actor_resolver');

            return $custom
                ? $app->make($custom)
                : new GuardActorResolver(config('datagrid.authorization.guard'));
        });

        $this->app->singleton(GridRegistry::class, fn ($app) => new GridRegistry($app, config('datagrid.grids', [])));
    }

    public function boot(): void
    {
        if (config('datagrid.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/datagrid.php');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'datagrid');
        Blade::component('datagrid', Datagrid::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/datagrid.php' => config_path('datagrid.php')], 'datagrid-config');
            $this->publishes([__DIR__.'/../resources/dist' => public_path('vendor/datagrid')], 'datagrid-assets');
            $this->publishes([__DIR__.'/../resources/dist/datagrid.css' => public_path('vendor/datagrid/datagrid.css')], 'datagrid-css');
            $this->commands([DoctorCommand::class]);
        }
    }
}
