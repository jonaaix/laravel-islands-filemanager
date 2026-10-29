<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final readonly class Volume
{
    public const string HELPER_DIRECTORY = '.filemanager';

    public function __construct(
        public string $key,
        public string $name,
        public string $disk,
        public ?string $ability = null,
    ) {}

    public function filesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    public function isVisibleTo(Authenticatable $user): bool
    {
        return $this->ability === null || Gate::forUser($user)->allows($this->ability);
    }

    public function isLocal(): bool
    {
        return config("filesystems.disks.{$this->disk}.driver") === 'local';
    }

    public function helperPath(string $kind, string $hash, string $extension): string
    {
        return implode('/', [self::HELPER_DIRECTORY, $kind, substr($hash, 0, 2), substr($hash, 2, 2), $hash.'.'.$extension]);
    }

    /**
     * @return array{key: string, name: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => __($this->name)];
    }
}
