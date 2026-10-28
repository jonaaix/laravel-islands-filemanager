<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager;

use Aaix\LaravelIslandsFilemanager\Commands\ExtractFileTextCmd;
use Aaix\LaravelIslandsFilemanager\Http\PageController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FileManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filemanager.php', 'filemanager');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filemanager');

        $this->publishes([__DIR__.'/../config/filemanager.php' => config_path('filemanager.php')], 'filemanager-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'filemanager-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ExtractFileTextCmd::class]);
        }

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(config('filemanager.routes.middleware'))
            ->prefix(config('filemanager.routes.prefix'))
            ->name(config('filemanager.routes.name'))
            ->group(function (): void {
                Route::get('/', [PageController::class, 'index'])->name('index');
                Route::prefix('api')->group(__DIR__.'/../routes/filemanager.php');
            });
    }
}
