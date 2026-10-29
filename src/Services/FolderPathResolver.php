<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Illuminate\Contracts\Auth\Authenticatable;

class FolderPathResolver
{
    /** @var array<string, Folder> */
    private array $resolved = [];

    public function resolve(Location $base, string $relativePath, Authenticatable $user): Location
    {
        $folder = $base->folder;
        $key = $base->volume->key.':'.$base->folderId();

        foreach ($this->segments($relativePath) as $name) {
            $key .= '/'.$name;
            $folder = $this->resolved[$key] ??= Folder::query()->firstOrCreate(
                ['volume' => $base->volume->key, 'parent_id' => $folder?->id, 'name' => $name],
                ['created_by_id' => $user->getAuthIdentifier()],
            );
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
