<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Tests;

use Aaix\LaravelIslands\IslandsServiceProvider;
use Aaix\LaravelIslandsFilemanager\FileManagerServiceProvider;
use Aaix\LaravelIslandsFilemanager\Search\FileContentSearchSource;
use Aaix\LaravelIslandsFilemanager\Search\FileSearchSource;
use Aaix\LaravelIslandsFilemanager\Tests\Fixtures\User;
use Aaix\LaravelIslandsSearch\IslandsSearch;
use Aaix\LaravelIslandsSearch\IslandsSearchServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('filemanager');
        Storage::fake('archive');
        Gate::define('filemanager.access', fn (User $user): bool => $user->may_use_files);
        Gate::define('filemanager.archive', fn (User $user): bool => str_starts_with($user->name, 'Member'));
    }

    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            IslandsServiceProvider::class,
            IslandsSearchServiceProvider::class,
            FileManagerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('filemanager.hash_salt', 'test-salt');
        $app['config']->set('filemanager.volumes', [
            'files' => ['name' => 'Files', 'disk' => 'filemanager'],
            'archive' => ['name' => 'Archive', 'disk' => 'archive', 'ability' => 'filemanager.archive'],
        ]);
        $app['config']->set('filesystems.disks.archive', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/archive')]);
        $app['config']->set('filemanager.page.view', 'fixtures::page');
        $app['config']->set('queue.default', 'sync');
        $app['view']->addNamespace('fixtures', __DIR__.'/Fixtures/views');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'auth'])->group(function (): void {
            IslandsSearch::route('search', [FileSearchSource::class, FileContentSearchSource::class])->name('search');
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('may_use_files')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        foreach (glob(__DIR__.'/../database/migrations/*.php') as $migration) {
            (require $migration)->up();
        }
    }

    protected function member(array $attributes = []): User
    {
        static $count = 0;
        $count++;

        return User::query()->create(['name' => 'Member '.$count, 'email' => "member{$count}@example.com", 'password' => 'secret', 'may_use_files' => true, ...$attributes]);
    }
}
