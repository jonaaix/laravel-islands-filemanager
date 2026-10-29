<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\Comment;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemCopier
{
    public function __construct(
        private readonly FolderTree $tree,
        private readonly TextIndex $index,
        private readonly FilePlacement $placement,
        private readonly Fingerprints $fingerprints,
    ) {}

    /**
     * @param  Collection<int, Folder>  $folders
     * @param  Collection<int, File>  $files
     * @return array{folders: list<int>, files: list<int>}
     */
    public function copy(Collection $folders, Collection $files, Location $target, Authenticatable $user): array
    {
        return DB::transaction(function () use ($folders, $files, $target, $user): array {
            $copies = ['folders' => [], 'files' => []];

            foreach ($folders as $folder) {
                $this->ensureNotInsideItself($folder, $target);
                $copies['folders'][] = $this->copyFolder($folder, $target, $user)->id;
            }

            foreach ($files as $file) {
                $copies['files'][] = $this->copyFile($file, $target)->id;
            }

            return $copies;
        });
    }

    private function ensureNotInsideItself(Folder $folder, Location $target): void
    {
        if ($target->folder !== null && $target->isSameVolume($folder->getAttribute('volume')) && $this->tree->descendantIds($folder)->contains($target->folderId())) {
            throw ValidationException::withMessages(['target_id' => __('A folder cannot be copied into itself.')]);
        }
    }

    private function copyFolder(Folder $folder, Location $target, Authenticatable $user): Folder
    {
        $copy = $this->placement->createFolder($target, $this->tree->freeName($folder->name, $target), $user);

        $inside = new Location($target->volume, $copy);
        $folder->children()->get()->each(fn (Folder $child) => $this->copyFolder($child, $inside, $user));
        $folder->files()->get()->each(fn (File $file) => $this->copyFile($file, $inside));

        return $copy;
    }

    private function copyFile(File $file, Location $target): File
    {
        $copy = $file->replicate(['path', 'thumbnail_path']);
        $copy->forceFill(['volume' => $target->volume->key, 'folder_id' => $target->folderId()])->save();

        $source = $file->mediaDisk();
        $destination = $copy->mediaDisk();
        $paths = [];

        if ($file->path !== null && $source->exists($file->path)) {
            $paths['path'] = $this->placement->pathForNewFile($copy, $target, $file->name);
            $paths['name'] = $target->volume->isDirect() ? basename($paths['path']) : $file->name;
            $destination->writeStream($paths['path'], $source->readStream($file->path));
        }

        if ($file->thumbnail_path !== null && $source->exists($file->thumbnail_path)) {
            $paths['thumbnail_path'] = $copy->volume()->helperPath('thumbnails', $copy->mediaHash(), 'webp');
            $destination->writeStream($paths['thumbnail_path'], $source->readStream($file->thumbnail_path));
        }

        $copy->forceFill($paths)->save();
        $this->fingerprints->refresh($copy);

        if ($file->content !== null) {
            $this->index->store($copy, $file->content->text);
        }

        $file->comments()->get()->each(fn (Comment $comment) => $comment->replicate()->forceFill(['file_id' => $copy->id, 'created_at' => $comment->created_at])->save());

        return $copy;
    }
}
