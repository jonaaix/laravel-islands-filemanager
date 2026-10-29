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
    ) {}

    /**
     * @param  Collection<int, Folder>  $folders
     * @param  Collection<int, File>  $files
     */
    public function copy(Collection $folders, Collection $files, Location $target, Authenticatable $user): void
    {
        DB::transaction(function () use ($folders, $files, $target, $user): void {
            foreach ($folders as $folder) {
                $this->ensureNotInsideItself($folder, $target);
                $this->copyFolder($folder, $target, $user);
            }

            $files->each(fn (File $file) => $this->copyFile($file, $target));
        });
    }

    private function ensureNotInsideItself(Folder $folder, Location $target): void
    {
        if ($target->folder !== null && $target->isSameVolume($folder->getAttribute('volume')) && $this->tree->descendantIds($folder)->contains($target->folderId())) {
            throw ValidationException::withMessages(['target_id' => __('A folder cannot be copied into itself.')]);
        }
    }

    private function copyFolder(Folder $folder, Location $target, Authenticatable $user): void
    {
        $copy = Folder::query()->create([
            'volume' => $target->volume->key,
            'parent_id' => $target->folderId(),
            'name' => $this->tree->freeName($folder->name, $target),
            'created_by_id' => $user->getAuthIdentifier(),
        ]);

        $inside = new Location($target->volume, $copy);
        $folder->children()->get()->each(fn (Folder $child) => $this->copyFolder($child, $inside, $user));
        $folder->files()->get()->each(fn (File $file) => $this->copyFile($file, $inside));
    }

    private function copyFile(File $file, Location $target): File
    {
        $copy = $file->replicate(['path', 'thumbnail_path']);
        $copy->forceFill(['volume' => $target->volume->key, 'folder_id' => $target->folderId()])->save();

        $source = $file->mediaDisk();
        $destination = $copy->mediaDisk();
        $paths = [];

        if ($file->path !== null && $source->exists($file->path)) {
            $paths['path'] = $copy->mediaPath(basename($file->path));
            $destination->writeStream($paths['path'], $source->readStream($file->path));
        }

        if ($file->thumbnail_path !== null && $source->exists($file->thumbnail_path)) {
            $paths['thumbnail_path'] = $copy->volume()->helperPath('thumbnails', $copy->mediaHash(), 'webp');
            $destination->writeStream($paths['thumbnail_path'], $source->readStream($file->thumbnail_path));
        }

        $copy->forceFill($paths)->save();

        if ($file->content !== null) {
            $this->index->store($copy, $file->content->text);
        }

        $file->comments()->get()->each(fn (Comment $comment) => $comment->replicate()->forceFill(['file_id' => $copy->id, 'created_at' => $comment->created_at])->save());

        return $copy;
    }
}
