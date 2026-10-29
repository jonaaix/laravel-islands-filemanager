<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Volume;
use Illuminate\Contracts\Auth\Authenticatable;

class FilePlacement
{
    public function __construct(private readonly Fingerprints $fingerprints) {}

    public function directoryOf(Location $at): string
    {
        return $at->folder?->disk_path ?? $at->volume->rootPath();
    }

    public function createFolder(Location $parent, string $name, ?Authenticatable $user): Folder
    {
        $diskPath = $parent->volume->isDirect() ? $this->join($this->directoryOf($parent), $name) : null;

        if ($diskPath !== null) {
            $parent->volume->filesystem()->makeDirectory($diskPath);
        }

        return Folder::query()->create([
            'volume' => $parent->volume->key,
            'parent_id' => $parent->folderId(),
            'name' => $name,
            'disk_path' => $diskPath,
            'created_by_id' => $user?->getAuthIdentifier(),
        ]);
    }

    public function pathForNewFile(File $file, Location $at, string $name): string
    {
        if (! $at->volume->isDirect()) {
            return $file->mediaPath('original.'.$this->extensionOf($name));
        }

        return $this->freeFilePath($at, $name, $file->id);
    }

    public function freeFilePath(Location $at, string $name, ?int $exceptFileId = null): string
    {
        $directory = $this->directoryOf($at);
        $disk = $at->volume->filesystem();
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
        $candidate = $this->join($directory, $name);

        for ($number = 2; $this->isTaken($at->volume, $candidate, $exceptFileId) || $disk->exists($candidate); $number++) {
            $candidate = $this->join($directory, $base.' '.$number.($extension === '' ? '' : '.'.$extension));
        }

        return $candidate;
    }

    public function moveFile(File $file, Location $target, ?string $newName = null): void
    {
        $from = $file->volume();
        $to = $target->volume;
        $name = $newName ?? $file->name;
        $sameVolume = $from->key === $to->key;

        if ($sameVolume && ! $to->isDirect()) {
            $file->update(['folder_id' => $target->folderId(), 'name' => $name]);

            return;
        }

        $newPath = $to->isDirect() ? $this->freeFilePath($target, $name, $file->id) : $file->mediaPath('original.'.$this->extensionOf($name));
        $this->relocate($file, $from, $to, $newPath);
        $file->update(['volume' => $to->key, 'folder_id' => $target->folderId(), 'name' => $to->isDirect() ? basename($newPath) : $name, 'path' => $newPath]);
        $this->fingerprints->refresh($file);
    }

    public function moveFolder(Folder $folder, Location $target, ?string $newName = null): void
    {
        $from = $folder->volume();
        $oldDiskPath = $folder->disk_path;

        if ($from->key === $target->volume->key && ! $from->isDirect()) {
            $folder->update(['parent_id' => $target->folderId(), 'name' => $newName ?? $folder->name]);

            return;
        }

        $folder->update([
            'volume' => $target->volume->key,
            'parent_id' => $target->folderId(),
            'name' => $newName ?? $folder->name,
        ]);

        $this->relocateTree($folder, $target->volume);

        if ($from->isDirect() && $oldDiskPath !== null && $oldDiskPath !== $folder->refresh()->disk_path) {
            $from->filesystem()->deleteDirectory($oldDiskPath);
        }
    }

    private function relocateTree(Folder $folder, Volume $volume): void
    {
        $diskPath = $volume->isDirect() ? $this->join($this->directoryOf(new Location($volume, $folder->parent()->first())), $folder->name) : null;
        $folder->update(['volume' => $volume->key, 'disk_path' => $diskPath]);

        if ($diskPath !== null) {
            $volume->filesystem()->makeDirectory($diskPath);
        }

        $here = new Location($volume, $folder);
        $folder->files()->get()->each(fn (File $file) => $this->moveFile($file, $here));
        $folder->children()->get()->each(fn (Folder $child) => $this->relocateTree($child, $volume));
    }

    private function relocate(File $file, Volume $from, Volume $to, string $newPath): void
    {
        $source = $from->filesystem();
        $target = $to->filesystem();
        $oldPath = $file->path;

        if ($oldPath !== null && ($oldPath !== $newPath || $from->key !== $to->key) && $source->exists($oldPath)) {
            if ($from->key === $to->key) {
                $source->move($oldPath, $newPath);
            } else {
                $target->writeStream($newPath, $source->readStream($oldPath));
                $source->delete($oldPath);

                if (! $from->isDirect()) {
                    $source->deleteDirectory(dirname($oldPath));
                }
            }
        }

        if ($from->key === $to->key) {
            return;
        }

        $helpers = array_filter([$file->thumbnail_path, $from->helperPath('text', $file->mediaHash(), 'txt')]);

        foreach ($helpers as $helper) {
            if ($source->exists($helper)) {
                $moved = $to->helperPath(str_contains($helper, '/thumbnails/') ? 'thumbnails' : 'text', $file->mediaHash(), pathinfo($helper, PATHINFO_EXTENSION));
                $target->writeStream($moved, $source->readStream($helper));
                $source->delete($helper);

                if (str_contains($helper, '/thumbnails/')) {
                    $file->thumbnail_path = $moved;
                }
            }
        }
    }

    private function isTaken(Volume $volume, string $path, ?int $exceptFileId): bool
    {
        return File::query()->where('volume', $volume->key)->where('path', $path)->when($exceptFileId, fn ($query) => $query->whereKeyNot($exceptFileId))->exists();
    }

    private function extensionOf(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';
    }

    private function join(string $directory, string $name): string
    {
        return ltrim($directory.'/'.str_replace(['/', '\\'], '-', $name), '/');
    }
}
