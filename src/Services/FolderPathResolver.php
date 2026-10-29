<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Illuminate\Contracts\Auth\Authenticatable;

class FolderPathResolver
{
    public function __construct(private readonly FilePlacement $placement) {}

    /** @var array<string, Folder> */
    private array $resolved = [];

    public function resolve(Location $base, string $relativePath, Authenticatable $user): Location
    {
        $folder = $base->folder;
        $key = $base->volume->key.':'.$base->folderId();

        foreach ($this->segments($relativePath) as $name) {
            $key .= '/'.$name;
            $parent = new Location($base->volume, $folder);
            $folder = $this->resolved[$key] ??= Folder::query()->where('volume', $base->volume->key)->where('parent_id', $folder?->id)->where('name', $name)->first()
                ?? $this->placement->createFolder($parent, $name, $user);
        }

        return new Location($base->volume, $folder);
    }

    /**
     * @return list<string>
     */
    private function segments(string $relativePath): array
    {
        $segments = array_map(fn (string $segment): string => trim($segment), explode('/', str_replace('\\', '/', $relativePath)));

        return array_values(array_filter($segments, fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..'));
    }
}
