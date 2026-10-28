<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Illuminate\Support\Collection;
use RuntimeException;
use ZipArchive;

class ZipBuilder
{
    /** @var array<string, true> */
    private array $usedEntries = [];

    /**
     * @param  Collection<int, Folder>  $folders
     * @param  Collection<int, File>  $files
     */
    public function build(Collection $folders, Collection $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'files-zip-');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the ZIP archive.');
        }

        $this->usedEntries = [];

        foreach ($folders as $folder) {
            $this->addFolder($zip, $folder, '');
        }

        foreach ($files as $file) {
            $this->addFile($zip, $file, '');
        }

        $zip->close();

        return $path;
    }

    private function addFolder(ZipArchive $zip, Folder $folder, string $prefix): void
    {
        $directory = $this->uniqueEntry($prefix.$this->clean($folder->name)).'/';
        $zip->addEmptyDir(rtrim($directory, '/'));

        foreach ($folder->children()->orderBy('name')->get() as $child) {
            $this->addFolder($zip, $child, $directory);
        }

        foreach ($folder->files()->orderBy('name')->get() as $file) {
            $this->addFile($zip, $file, $directory);
        }
    }

    private function addFile(ZipArchive $zip, File $file, string $prefix): void
    {
        if ($file->path === null || ! $file->mediaDisk()->exists($file->path)) {
            return;
        }

        $zip->addFile($file->mediaDisk()->path($file->path), $this->uniqueEntry($prefix.$this->clean($file->name)));
    }

    private function uniqueEntry(string $entry): string
    {
        $candidate = $entry;
        $extension = pathinfo($entry, PATHINFO_EXTENSION);
        $base = $extension === '' ? $entry : substr($entry, 0, -strlen($extension) - 1);

        for ($number = 2; isset($this->usedEntries[$candidate]); $number++) {
            $candidate = $base.' '.$number.($extension === '' ? '' : '.'.$extension);
        }

        $this->usedEntries[$candidate] = true;

        return $candidate;
    }

    private function clean(string $name): string
    {
        return trim(str_replace(['/', '\\'], '-', $name)) ?: 'untitled';
    }
}
