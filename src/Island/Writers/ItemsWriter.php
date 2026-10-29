<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Island\Writers;

use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Services\FolderTree;
use Aaix\LaravelIslandsFilemanager\Services\VolumeTransfer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ItemsWriter
{
    public function __construct(
        private readonly FolderWriter $folders,
        private readonly FolderTree $tree,
        private readonly VolumeTransfer $transfer,
    ) {}

    /**
     * @param  Collection<int, Folder>  $folders
     * @param  Collection<int, File>  $files
     */
    public function move(Collection $folders, Collection $files, Location $target): void
    {
        DB::transaction(function () use ($folders, $files, $target): void {
            $folders->each(fn (Folder $folder) => $this->folders->move($folder, $target));
            $files->each(function (File $file) use ($target): void {
                $this->transfer->moveFile($file, $target->volume);
                $file->update(['folder_id' => $target->folderId()]);
            });
        });
    }

    /**
     * @param  Collection<int, Folder>  $folders
     * @param  Collection<int, File>  $files
     */
    public function moveIntoNewFolder(Collection $folders, Collection $files, Location $parent, Authenticatable $user): Folder
    {
        return DB::transaction(function () use ($folders, $files, $parent, $user): Folder {
            $folder = $this->folders->create(null, $parent, $user, 'New folder with items');
            $this->move($folders, $files, Location::of($folder));

            return $folder;
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
