<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Island\Writers;

use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Services\FolderTree;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class FolderWriter
{
    public function __construct(private readonly FolderTree $tree) {}

    public function create(?string $name, ?Folder $parent, Authenticatable $user): Folder
    {
        $name = filled($name) ? trim($name) : $this->tree->freeName(__('New folder'), $parent?->id);
        $this->ensureNameIsFree($name, $parent?->id);

        return Folder::query()->create(['name' => $name, 'parent_id' => $parent?->id, 'created_by_id' => $user->getAuthIdentifier()]);
    }

    public function rename(Folder $folder, string $name): void
    {
        $this->ensureNameIsFree($name, $folder->parent_id, $folder->id);
        $folder->update(['name' => $name]);
    }

    public function move(Folder $folder, ?Folder $target): void
    {
        if (! $this->tree->canMoveInto($folder, $target)) {
            throw ValidationException::withMessages(['target_id' => __('A folder cannot be moved into itself.')]);
        }

        $this->ensureNameIsFree($folder->name, $target?->id, $folder->id);
        $folder->update(['parent_id' => $target?->id]);
    }

    private function ensureNameIsFree(string $name, ?int $parentId, ?int $exceptId = null): void
    {
        $taken = Folder::query()
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('A folder with this name already exists here.')]);
        }
    }
}
