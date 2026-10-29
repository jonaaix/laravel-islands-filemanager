<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Volume;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Trash
{
    public function __construct(
        private readonly FilePlacement $placement,
        private readonly FolderTree $tree,
        private readonly Fingerprints $fingerprints,
    ) {}

    /**
     * @param  Collection<int, Folder>  $folders
     * @param  Collection<int, File>  $files
     */
    public function throwAway(Collection $folders, Collection $files, Authenticatable $user): void
    {
        $selectedIds = $folders->pluck('id');
        $topmost = $folders->reject(fn (Folder $folder): bool => collect($this->tree->ancestry($folder->parent))->contains(fn (Folder $ancestor): bool => $selectedIds->contains($ancestor->id)));

        DB::transaction(function () use ($topmost, $files, $user): void {
            foreach ($topmost as $folder) {
                $this->throwAwayFolder($folder, $user);
            }

            foreach ($files as $file) {
                if (File::query()->whereKey($file->id)->exists()) {
                    $this->throwAwayFile($file->refresh(), $user);
                }
            }
        });
    }

    /**
     * @return Collection<int, Folder|File>
     */
    public function entries(Volume $volume): Collection
    {
        $folders = Folder::onlyTrashed()->where('volume', $volume->key)->where('trash_root', true)->with('deletedBy')->get();
        $files = File::onlyTrashed()->where('volume', $volume->key)->where('trash_root', true)->with('deletedBy')->get();

        return $folders->toBase()->concat($files)->sortByDesc('deleted_at')->values();
    }

    /**
     * @return list<string>
     */
    public function originalPath(Folder|File $entry): array
    {
        $parentId = $entry instanceof Folder ? $entry->parent_id : $entry->folder_id;
        $names = [];

        while ($parentId !== null && ($parent = Folder::withTrashed()->where('volume', $entry->getAttribute('volume'))->find($parentId)) !== null) {
            array_unshift($names, $parent->name);
            $parentId = $parent->parent_id;
        }

        return [__($entry->volume()->name), ...$names];
    }

    public function restore(Folder|File $entry): void
    {
        DB::transaction(fn () => $entry instanceof Folder ? $this->restoreFolder($entry) : $this->restoreFile($entry));
    }

    public function purge(Folder|File $entry): void
    {
        if (! $entry::withTrashed()->whereKey($entry->id)->exists()) {
            return;
        }

        DB::transaction(fn () => $entry instanceof Folder ? $this->purgeFolder($entry) : $this->dropFile($entry));
    }

    public function empty(Volume $volume): int
    {
        $entries = $this->entries($volume);
        $entries->each(fn (Folder|File $entry) => $this->purge($entry));

        return $entries->count();
    }

    public function purgeOlderThan(int $days): int
    {
        $cutoff = now()->subDays($days);
        $folders = Folder::onlyTrashed()->where('trash_root', true)->where('deleted_at', '<', $cutoff)->get();
        $files = File::onlyTrashed()->where('trash_root', true)->where('deleted_at', '<', $cutoff)->get();

        $folders->concat($files)->each(fn (Folder|File $entry) => $this->purge($entry));

        return $folders->count() + $files->count();
    }

    // ----- Throwing away -----

    private function throwAwayFolder(Folder $folder, Authenticatable $user): void
    {
        $memberIds = $this->tree->descendantIds($folder);
        $trashed = ['deleted_at' => now(), 'trash_root' => false, 'deleted_by_id' => $user->getAuthIdentifier()];

        File::query()->whereIn('folder_id', $memberIds)->update($trashed);
        Folder::query()->whereIn('id', $memberIds)->update($trashed);
        Folder::onlyTrashed()->whereKey($folder->id)->update(['trash_root' => true]);
    }

    private function throwAwayFile(File $file, Authenticatable $user): void
    {
        $file->forceFill(['trash_root' => true, 'deleted_by_id' => $user->getAuthIdentifier()])->save();
        $file->delete();
    }

    // ----- Restoring -----

    private function restoreFolder(Folder $folder): void
    {
        $volume = $folder->volume();
        $memberIds = $this->members($folder);
        $parent = $this->activeFolder($volume, $folder->parent_id);
        $staysInPlace = $parent?->id === $folder->parent_id;
        $at = new Location($volume, $parent);
        $name = $staysInPlace && $volume->isDirect() ? $folder->name : $this->freeFolderName($folder->name, $at);

        $restored = ['deleted_at' => null, 'trash_root' => false, 'deleted_by_id' => null];
        Folder::onlyTrashed()->whereIn('id', $memberIds)->update($restored);
        File::onlyTrashed()->whereIn('folder_id', $memberIds)->where('trash_root', false)->update($restored);

        $folder->refresh()->update(['parent_id' => $parent?->id, 'name' => $name]);

        if ($volume->isDirect() && ! $staysInPlace && $folder->disk_path !== null) {
            $this->placement->relocateDirectory($folder, $this->placement->join($at->directory(), $name));
        }
    }

    private function restoreFile(File $file): void
    {
        $volume = $file->volume();
        $folder = $this->activeFolder($volume, $file->folder_id);
        $attributes = ['folder_id' => $folder?->id, 'trash_root' => false, 'deleted_by_id' => null];
        $staysInPlace = $folder?->id === $file->folder_id;

        if ($volume->isDirect() && ! $staysInPlace && $file->path !== null && $volume->filesystem()->exists($file->path)) {
            $path = $this->placement->freeFilePath(new Location($volume, $folder), $file->name, $file->id);
            $volume->filesystem()->move($file->path, $path);
            $attributes += ['path' => $path, 'name' => basename($path)];
        }

        $file->forceFill($attributes)->save();
        $file->restore();
        $this->fingerprints->refresh($file);
    }

    // ----- Purging -----

    private function purgeFolder(Folder $folder): void
    {
        $volume = $folder->volume();
        $ids = $this->subtree($folder);
        $directories = Folder::withTrashed()->whereIn('id', $ids)->pluck('disk_path', 'id');

        File::withTrashed()->whereIn('folder_id', $ids)->get()->each(fn (File $file) => $this->dropFile($file));

        foreach (array_reverse($ids) as $id) {
            Folder::withTrashed()->whereKey($id)->forceDelete();

            if ($volume->isDirect() && filled($directories[$id] ?? null)) {
                $this->deleteDirectoryIfUnused($volume, $directories[$id]);
            }
        }
    }

    private function dropFile(File $file): void
    {
        $volume = $file->volume();
        $sharesPath = $volume->isDirect() && File::query()->where('volume', $volume->key)->where('path', $file->path)->whereKeyNot($file->id)->exists();

        $sharesPath ? $file->forget() : $file->forceDelete();
    }

    private function deleteDirectoryIfUnused(Volume $volume, string $directory): void
    {
        $disk = $volume->filesystem();
        $visibleFiles = array_filter($disk->allFiles($directory), fn (string $path): bool => ! str_contains('/'.$path, '/.'));

        // Files added from outside after the folder was thrown away were never part of it.
        if ($visibleFiles === [] && ! Folder::query()->where('volume', $volume->key)->where('disk_path', $directory)->exists()) {
            $disk->deleteDirectory($directory);
        }
    }

    // ----- Support -----

    /**
     * @return list<int>
     */
    private function members(Folder $root): array
    {
        $ids = [$root->id];
        $level = [$root->id];

        while ($level !== []) {
            $level = Folder::onlyTrashed()->whereIn('parent_id', $level)->where('trash_root', false)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function subtree(Folder $root): array
    {
        $ids = [$root->id];
        $level = [$root->id];

        while ($level !== []) {
            $level = Folder::withTrashed()->whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    private function activeFolder(Volume $volume, ?int $id): ?Folder
    {
        return $id === null ? null : Folder::query()->where('volume', $volume->key)->find($id);
    }

    private function freeFolderName(string $base, Location $at): string
    {
        $isTaken = fn (string $name): bool => Folder::query()->where('volume', $at->volume->key)->where('parent_id', $at->folderId())->where('name', $name)->exists()
            || ($at->volume->isDirect() && $at->volume->filesystem()->directoryExists($this->placement->join($at->directory(), $name)));
        $name = $base;

        for ($number = 2; $isTaken($name); $number++) {
            $name = $base.' '.$number;
        }

        return $name;
    }

    public static function retentionDays(): ?int
    {
        $days = config('filemanager.trash_days');

        return $days === null ? null : (int) $days;
    }
}
