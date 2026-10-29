<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Jobs\ExtractFileTextJob;
use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Volume;
use Illuminate\Support\Collection;

class DirectScanner
{
    public function __construct(private readonly Fingerprints $fingerprints) {}

    public function scanFolder(Location $at): void
    {
        if (! $at->volume->isDirect()) {
            return;
        }

        [$appeared, $vanished] = $this->syncLevel($at);
        $this->reconcile($at->volume, $appeared, $vanished);
    }

    public function scanVolume(Volume $volume): int
    {
        return $this->scanTree(new Location($volume));
    }

    public function scanTree(Location $start): int
    {
        if (! $start->volume->isDirect()) {
            return 0;
        }

        $volume = $start->volume;
        $appeared = [];
        $vanished = collect();
        $queue = [$start];
        $levels = 0;

        while ($at = array_shift($queue)) {
            [$newPaths, $gone, $children] = $this->syncLevel($at, withChildren: true);
            $appeared = [...$appeared, ...$newPaths];
            $vanished = $vanished->concat($gone);
            $queue = [...$queue, ...array_map(fn (Folder $child): Location => new Location($volume, $child), $children)];
            $levels++;
        }

        $this->reconcile($volume, $appeared, $vanished);

        return $levels;
    }

    /**
     * @return array{0: list<array{at: Location, path: string}>, 1: Collection<int, File>, 2: list<Folder>}
     */
    private function syncLevel(Location $at, bool $withChildren = false): array
    {
        $disk = $at->volume->filesystem();
        $directory = $at->directory();
        $visible = fn (string $path): bool => ! str_starts_with(basename($path), '.');

        $diskFolders = array_values(array_filter($disk->directories($directory), $visible));
        $known = Folder::query()->where('volume', $at->volume->key)->where('parent_id', $at->folderId())->get();

        foreach ($known as $folder) {
            if (! in_array($folder->disk_path, $diskFolders, true)) {
                $this->forgetFolder($folder);
            }
        }

        $children = [];

        foreach ($diskFolders as $path) {
            $children[] = $known->firstWhere('disk_path', $path) ?? Folder::query()->create([
                'volume' => $at->volume->key,
                'parent_id' => $at->folderId(),
                'name' => basename($path),
                'disk_path' => $path,
            ]);
        }

        $diskFiles = array_values(array_filter($disk->files($directory), $visible));
        $files = File::query()->where('volume', $at->volume->key)->where('folder_id', $at->folderId())->get();
        $appeared = array_map(
            fn (string $path): array => ['at' => $at, 'path' => $path],
            array_values(array_diff($diskFiles, $files->pluck('path')->all())),
        );
        $vanished = $files->reject(fn (File $file): bool => in_array($file->path, $diskFiles, true))->values();

        return [$appeared, $vanished, $withChildren ? $children : []];
    }

    /**
     * @param  list<array{at: Location, path: string}>  $appeared
     * @param  Collection<int, File>  $vanished
     */
    private function reconcile(Volume $volume, array $appeared, Collection $vanished): void
    {
        $unclaimed = $vanished->keyBy('id');

        foreach ($appeared as ['at' => $at, 'path' => $path]) {
            $fingerprint = $this->fingerprints->of($volume, $path);
            $previous = $unclaimed->first(fn (File $file): bool => $file->fingerprint === $fingerprint)
                ?? $this->movedFromElsewhere($volume, $fingerprint);

            if ($previous !== null) {
                $unclaimed->forget($previous->id);
                $previous->update(['folder_id' => $at->folderId(), 'path' => $path, 'name' => basename($path)]);

                continue;
            }

            $this->register($volume, $at, $path, $fingerprint);
        }

        $unclaimed->each(fn (File $file) => $file->forget());
    }

    private function movedFromElsewhere(Volume $volume, string $fingerprint): ?File
    {
        $candidate = File::query()->where('volume', $volume->key)->where('fingerprint', $fingerprint)->first();

        return $candidate !== null && ! $volume->filesystem()->exists($candidate->path) ? $candidate : null;
    }

    private function register(Volume $volume, Location $at, string $path, string $fingerprint): void
    {
        $disk = $volume->filesystem();

        $file = File::query()->create([
            'volume' => $volume->key,
            'folder_id' => $at->folderId(),
            'name' => basename($path),
            'path' => $path,
            'mime_type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'size' => $disk->size($path),
            'fingerprint' => $fingerprint,
        ]);

        ExtractFileTextJob::dispatch($file->id, withThumbnail: true);
    }

    private function forgetFolder(Folder $folder): void
    {
        $folder->children()->get()->each(fn (Folder $child) => $this->forgetFolder($child));
        $folder->files()->get()->each(fn (File $file) => $file->forget());
        $folder->delete();
    }
}
