<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class FileManager
{
    /**
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        return config('filemanager.user_model') ?? config('auth.providers.users.model');
    }

    public static function allows(?Authenticatable $user): bool
    {
        $ability = config('filemanager.ability');

        return $user !== null && ($ability === null || Gate::forUser($user)->allows($ability));
    }

    public static function table(string $name): string
    {
        return config('filemanager.table_prefix', 'filemanager_').$name;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters = []): string
    {
        return route(config('filemanager.routes.name', 'filemanager.').$name, $parameters);
    }
}
