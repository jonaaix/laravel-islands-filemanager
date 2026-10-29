<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Illuminate\Support\Collection;

class FolderTree
{
    /**
     * @return list<Folder>
     */
    public function ancestry(?Folder $folder): array
    {
        $chain = [];

        while ($folder !== null) {
            array_unshift($chain, $folder);
            $folder = $folder->parent;
        }

        return $chain;
    }

    /**
     * @return list<array{folder: Folder, depth: int}>
     */
    public function flattened(string $volume): array
    {
        $byParent = Folder::query()->where('volume', $volume)->orderBy('name')->get()->groupBy(fn (Folder $folder): string => (string) $folder->parent_id);
        $rows = [];

        $walk = function (string $parentKey, int $depth) use (&$walk, &$rows, $byParent): void {
            foreach ($byParent->get($parentKey, []) as $folder) {
                $rows[] = ['folder' => $folder, 'depth' => $depth];
                $walk((string) $folder->id, $depth + 1);
            }
        };

        $walk('', 0);

        return $rows;
    }

    public function freeName(string $base, Location $at): string
    {
        $taken = Folder::query()->where('volume', $at->volume->key)->where('parent_id', $at->folderId())->pluck('name')->flip();
        $name = $base;

        for ($number = 2; $taken->has($name); $number++) {
            $name = $base.' '.$number;
        }

        return $name;
    }

    /**
     * @return Collection<int, int>
     */
    public function descendantIds(Folder $folder): Collection
    {
        $ids = collect([$folder->id]);
        $level = [$folder->id];

        while ($level !== []) {
            $level = Folder::query()->whereIn('parent_id', $level)->pluck('id')->all();
            $ids = $ids->merge($level);
        }

        return $ids;
    }

    public function canMoveInto(Folder $folder, ?Folder $target): bool
    {
        return $target === null || ! $this->descendantIds($folder)->contains($target->id);
    }

    public function delete(Folder $folder): void
    {
        $ids = $this->descendantIds($folder);

        File::query()->whereIn('folder_id', $ids)->get()->each->delete();
        $folder->delete();

        if ($folder->disk_path !== null && $folder->volume()->isDirect()) {
            $folder->volume()->filesystem()->deleteDirectory($folder->disk_path);
        }
    }
}
