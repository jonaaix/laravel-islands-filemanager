<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Island\Writers;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Services\FolderTree;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ItemsWriter
{
    public function __construct(
        private readonly FolderWriter $folders,
        private readonly FolderTree $tree,
    ) {}

    /**
     * @param  Collection<int, Folder>  $folders
     * @param  Collection<int, File>  $files
     */
    public function move(Collection $folders, Collection $files, ?Folder $target): void
    {
        DB::transaction(function () use ($folders, $files, $target): void {
            $folders->each(fn (Folder $folder) => $this->folders->move($folder, $target));
            $files->each(fn (File $file) => $file->update(['folder_id' => $target?->id]));
        });
    }

    /**
     * @param  Collection<int, Folder>  $folders
     * @param  Collection<int, File>  $files
     */
    public function delete(Collection $folders, Collection $files): void
    {
        $files->each->delete();

        foreach ($folders as $folder) {
            if (Folder::query()->whereKey($folder->id)->exists()) {
                $this->tree->delete($folder);
            }
        }
    }
}
