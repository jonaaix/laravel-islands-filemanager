<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Island\Writers;

use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Services\FilePlacement;
use Aaix\LaravelIslandsFilemanager\Services\FolderTree;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class FolderWriter
{
    public function __construct(
        private readonly FolderTree $tree,
        private readonly FilePlacement $placement,
    ) {}

    public function create(?string $name, Location $at, Authenticatable $user, string $fallbackName = 'New folder'): Folder
    {
        $name = filled($name) ? trim($name) : $this->tree->freeName(__($fallbackName), $at);
        $this->ensureNameIsFree($name, $at);

        return $this->placement->createFolder($at, $name, $user);
    }

    public function rename(Folder $folder, string $name): void
    {
        $parent = new Location($folder->volume(), $folder->parent);
        $this->ensureNameIsFree($name, $parent, $folder->id);
        $this->placement->moveFolder($folder, $parent, $name);
    }

    public function move(Folder $folder, Location $target): void
    {
        if ($target->isSameVolume($folder->getAttribute('volume')) && ! $this->tree->canMoveInto($folder, $target->folder)) {
            throw ValidationException::withMessages(['target_id' => __('A folder cannot be moved into itself.')]);
        }

        $this->ensureNameIsFree($folder->name, $target, $folder->id);
        $this->placement->moveFolder($folder, $target);
    }

    private function ensureNameIsFree(string $name, Location $at, ?int $exceptId = null): void
    {
        $taken = Folder::query()
            ->where('volume', $at->volume->key)
            ->where('parent_id', $at->folderId())
            ->where('name', $name)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        $takenOnDisk = $at->volume->isDirect() && $at->volume->filesystem()->directoryExists(ltrim($at->directory().'/'.$name, '/'));
        $isItself = $exceptId !== null && Folder::query()->whereKey($exceptId)->value('disk_path') === ltrim($at->directory().'/'.$name, '/');

        if ($taken || ($takenOnDisk && ! $isItself)) {
            throw ValidationException::withMessages(['name' => __('A folder with this name already exists here.')]);
        }
    }
}
