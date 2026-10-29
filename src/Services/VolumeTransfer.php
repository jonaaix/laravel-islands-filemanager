<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Volume;

class VolumeTransfer
{
    public function __construct(private readonly FolderTree $tree) {}

    public function moveFolder(Folder $folder, Volume $to): void
    {
        if ($folder->getAttribute('volume') === $to->key) {
            return;
        }

        $ids = $this->tree->descendantIds($folder);

        File::query()->whereIn('folder_id', $ids)->get()->each(fn (File $file) => $this->moveFile($file, $to));
        Folder::query()->whereKey($ids)->update(['volume' => $to->key]);
        $folder->setAttribute('volume', $to->key);
    }

    public function moveFile(File $file, Volume $to): void
    {
        $from = $file->volume();

        if ($from->key === $to->key) {
            return;
        }

        $paths = array_filter([$file->path, $file->thumbnail_path, $from->helperPath('text', $file->mediaHash(), 'txt')]);
        $source = $from->filesystem();
        $target = $to->filesystem();

        foreach ($paths as $path) {
            if ($source->exists($path)) {
                $target->writeStream($path, $source->readStream($path));
            }
        }

        $file->update(['volume' => $to->key]);
        $source->delete(array_values($paths));

        if ($file->path !== null) {
            $source->deleteDirectory(dirname($file->path));
        }
    }
}
