<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Illuminate\Contracts\Auth\Authenticatable;

class FolderPathResolver
{
    /** @var array<string, Folder|null> */
    private array $resolved = [];

    public function resolve(?Folder $base, string $relativePath, Authenticatable $user): ?Folder
    {
        $folder = $base;
        $key = (string) $base?->id;

        foreach ($this->segments($relativePath) as $name) {
            $key .= '/'.$name;
            $folder = $this->resolved[$key] ??= Folder::query()->firstOrCreate(
                ['parent_id' => $folder?->id, 'name' => $name],
                ['created_by_id' => $user->getAuthIdentifier()],
            );
        }

        return $folder;
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
