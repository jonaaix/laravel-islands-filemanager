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
    public function __construct(
        private readonly Fingerprints $fingerprints,
        private readonly DirectScanner $scanner,
    ) {}

    public function createFolder(Location $parent, string $name, ?Authenticatable $user): Folder
    {
        $diskPath = $parent->volume->isDirect() ? $this->join($parent->directory(), $name) : null;

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
        $directory = $at->directory();
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
        $name = $newName ?? $folder->name;

        if ($from->key === $target->volume->key && ! $from->isDirect()) {
            $folder->update(['parent_id' => $target->folderId(), 'name' => $name]);

            return;
        }

        if ($from->key === $target->volume->key) {
            $this->moveDirectoryInPlace($folder, $target, $name);

            return;
        }

        // Files that exist only on the disk would be left behind otherwise; every one needs a record first.
        $this->scanner->scanTree(Location::of($folder));
        $oldDiskPath = $folder->refresh()->disk_path;

        $folder->update(['volume' => $target->volume->key, 'parent_id' => $target->folderId(), 'name' => $name]);
        $this->relocateTree($folder, $target->volume);

        if ($from->isDirect() && $oldDiskPath !== null && $from->filesystem()->allFiles($oldDiskPath) === []) {
            $from->filesystem()->deleteDirectory($oldDiskPath);
        }
    }

    private function moveDirectoryInPlace(Folder $folder, Location $target, string $name): void
    {
        $this->relocateDirectory($folder, $this->join($target->directory(), $name));
        $folder->update(['parent_id' => $target->folderId(), 'name' => $name]);
    }

    public function relocateDirectory(Folder $folder, string $newPrefix): void
    {
        $disk = $folder->volume()->filesystem();
        $oldPrefix = (string) $folder->disk_path;

        if ($oldPrefix !== $newPrefix) {
            foreach ($disk->allDirectories($oldPrefix) as $directory) {
                $disk->makeDirectory($newPrefix.substr($directory, strlen($oldPrefix)));
            }

            $disk->makeDirectory($newPrefix);

            foreach ($disk->allFiles($oldPrefix) as $path) {
                $disk->move($path, $newPrefix.substr($path, strlen($oldPrefix)));
            }

            if ($disk->allFiles($oldPrefix) === []) {
                $disk->deleteDirectory($oldPrefix);
            }
        }

        $this->rewritePrefix($folder, $oldPrefix, $newPrefix);
    }

    private function rewritePrefix(Folder $folder, string $oldPrefix, string $newPrefix): void
    {
        $folder->update(['disk_path' => $newPrefix.substr((string) $folder->disk_path, strlen($oldPrefix))]);

        $folder->files()->get()->each(fn (File $file) => $file->update([
            'path' => $newPrefix.substr((string) $file->path, strlen($oldPrefix)),
        ]));

        $folder->children()->get()->each(fn (Folder $child) => $this->rewritePrefix($child, $oldPrefix, $newPrefix));
    }

    private function relocateTree(Folder $folder, Volume $volume): void
    {
        $diskPath = $volume->isDirect() ? $this->join((new Location($volume, $folder->parent()->first()))->directory(), $folder->name) : null;
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

    public function join(string $directory, string $name): string
    {
        return ltrim($directory.'/'.str_replace(['/', '\\'], '-', $name), '/');
    }
}
