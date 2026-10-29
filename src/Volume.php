<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final readonly class Volume
{
    public const string HELPER_DIRECTORY = '.laravel-islands-filemanager';

    public const string VIRTUAL = 'virtual';

    public const string DIRECT = 'direct';

    public function __construct(
        public string $key,
        public string $name,
        public string $disk,
        public ?string $ability = null,
        public ?string $writeAbility = null,
        public string $mode = self::VIRTUAL,
        public string $root = '',
    ) {}

    public function isDirect(): bool
    {
        return $this->mode === self::DIRECT;
    }

    public function rootPath(string $relative = ''): string
    {
        return trim(implode('/', array_filter([trim($this->root, '/'), trim($relative, '/')], fn (string $part): bool => $part !== '')), '/');
    }

    public function filesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    public function isVisibleTo(Authenticatable $user): bool
    {
        return $this->ability === null || Gate::forUser($user)->allows($this->ability);
    }

    public function isWritableBy(Authenticatable $user): bool
    {
        return $this->isVisibleTo($user) && ($this->writeAbility === null || Gate::forUser($user)->allows($this->writeAbility));
    }

    public function isLocal(): bool
    {
        return config("filesystems.disks.{$this->disk}.driver") === 'local';
    }

    public function helperPath(string $kind, string $hash, string $extension): string
    {
        return $this->rootPath(implode('/', [self::HELPER_DIRECTORY, $kind, substr($hash, 0, 2), substr($hash, 2, 2), $hash.'.'.$extension]));
    }

    public function trashPath(string $entry, string $name): string
    {
        return $this->rootPath(implode('/', [self::HELPER_DIRECTORY, 'trash', $entry, str_replace(['/', '\\'], '-', $name)]));
    }

    public function isInTrash(string $path): bool
    {
        return str_starts_with($path, $this->rootPath(self::HELPER_DIRECTORY.'/trash').'/');
    }

    /**
     * @return array{key: string, name: string, canWrite: bool}
     */
    public function toArrayFor(Authenticatable $user): array
    {
        return ['key' => $this->key, 'name' => __($this->name), 'canWrite' => $this->isWritableBy($user)];
    }
}
