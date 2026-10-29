<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager;

use Aaix\LaravelIslandsFilemanager\Commands\ExtractFileTextCmd;
use Aaix\LaravelIslandsFilemanager\Commands\MeasureImagesCmd;
use Aaix\LaravelIslandsFilemanager\Commands\PurgeTrashCmd;
use Aaix\LaravelIslandsFilemanager\Commands\ScanVolumesCmd;
use Aaix\LaravelIslandsFilemanager\Http\PageController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FileManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filemanager.php', 'filemanager');

        if (! config()->has('filesystems.disks.filemanager')) {
            config(['filesystems.disks.filemanager' => [
                'driver' => 'local',
                'root' => storage_path('app/filemanager'),
                'serve' => false,
                'throw' => false,
            ]]);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filemanager');

        $this->publishes([__DIR__.'/../config/filemanager.php' => config_path('filemanager.php')], 'filemanager-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'filemanager-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ExtractFileTextCmd::class, MeasureImagesCmd::class, PurgeTrashCmd::class, ScanVolumesCmd::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config('filemanager.scan_schedule') !== null && collect(FileManager::volumes())->contains(fn (Volume $volume): bool => $volume->isDirect())) {
                $schedule->command('filemanager:scan')->cron(config('filemanager.scan_schedule'))->withoutOverlapping();
            }

            if (config('filemanager.trash_days') !== null) {
                $schedule->command('filemanager:purge-trash')->dailyAt('03:15')->withoutOverlapping();
            }
        });

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
