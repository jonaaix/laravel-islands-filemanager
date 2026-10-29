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

    public static function avatarOf(?Model $user): ?string
    {
        $attribute = config('filemanager.user_avatar_attribute');

        return $user !== null && $attribute !== null ? $user->getAttribute($attribute) : null;
    }

    public static function allows(?Authenticatable $user): bool
    {
        $ability = config('filemanager.ability');

        return $user !== null && ($ability === null || Gate::forUser($user)->allows($ability));
    }

    /**
     * @return array<string, Volume>
     */
    public static function volumes(): array
    {
        $volumes = [];

        foreach (config('filemanager.volumes', []) as $key => $volume) {
            $volumes[$key] = new Volume(
                key: $key,
                name: $volume['name'] ?? $key,
                disk: $volume['disk'],
                ability: $volume['ability'] ?? null,
                writeAbility: $volume['write_ability'] ?? null,
                mode: $volume['mode'] ?? Volume::VIRTUAL,
                root: $volume['root'] ?? '',
            );
        }

        return $volumes;
    }

    public static function volume(?string $key = null): Volume
    {
        $volumes = self::volumes();

        return $volumes[$key ?? array_key_first($volumes)] ?? throw new \InvalidArgumentException("Unknown file manager volume [{$key}].");
    }

    public static function defaultVolume(): string
    {
        return (string) array_key_first(self::volumes());
    }

    /**
     * @return array<string, Volume>
     */
    public static function volumesFor(Authenticatable $user): array
    {
        return array_filter(self::volumes(), fn (Volume $volume): bool => $volume->isVisibleTo($user));
    }

    public static function mayTransfer(Authenticatable $user): bool
    {
        $ability = config('filemanager.transfer_ability');

        return $ability === null || Gate::forUser($user)->allows($ability);
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
