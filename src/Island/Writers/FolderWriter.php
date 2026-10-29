<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Island\Writers;

use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Services\FolderTree;
use Aaix\LaravelIslandsFilemanager\Services\VolumeTransfer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class FolderWriter
{
    public function __construct(
        private readonly FolderTree $tree,
        private readonly VolumeTransfer $transfer,
    ) {}

    public function create(?string $name, Location $at, Authenticatable $user, string $fallbackName = 'New folder'): Folder
    {
        $name = filled($name) ? trim($name) : $this->tree->freeName(__($fallbackName), $at);
        $this->ensureNameIsFree($name, $at);

        return Folder::query()->create([
            'volume' => $at->volume->key,
            'name' => $name,
            'parent_id' => $at->folderId(),
            'created_by_id' => $user->getAuthIdentifier(),
        ]);
    }

    public function rename(Folder $folder, string $name): void
    {
        $this->ensureNameIsFree($name, new Location($folder->volume(), $folder->parent), $folder->id);
        $folder->update(['name' => $name]);
    }

    public function move(Folder $folder, Location $target): void
    {
        if ($target->isSameVolume($folder->getAttribute('volume')) && ! $this->tree->canMoveInto($folder, $target->folder)) {
            throw ValidationException::withMessages(['target_id' => __('A folder cannot be moved into itself.')]);
        }

        $this->ensureNameIsFree($folder->name, $target, $folder->id);
        $this->transfer->moveFolder($folder, $target->volume);
        $folder->update(['parent_id' => $target->folderId()]);
    }

    private function ensureNameIsFree(string $name, Location $at, ?int $exceptId = null): void
    {
        $taken = Folder::query()
            ->where('volume', $at->volume->key)
            ->where('parent_id', $at->folderId())
            ->where('name', $name)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('A folder with this name already exists here.')]);
        }
    }
}
