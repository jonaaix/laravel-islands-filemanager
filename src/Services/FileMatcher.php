<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FileMatcher
{
    private const int MAX_TERMS = 8;

    /** @var Collection<int, Folder>|null */
    private ?Collection $folders = null;

    /**
     * @return list<string>
     */
    public function terms(string $query): array
    {
        $terms = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($terms)), 0, self::MAX_TERMS);
    }

    /**
     * @param  Builder<File>  $files
     * @param  list<string>  $terms
     */
    public function whereFilesMatch(Builder $files, array $terms, bool $withContent): void
    {
        $ownFields = function (Builder $query, string $like) use ($withContent): void {
            $query->where('name', 'like', $like)->orWhere('notes', 'like', $like);

            if ($withContent) {
                $query->orWhereHas('content', fn (Builder $content) => $content->where('text', 'like', $like));
            }
        };

        foreach ($terms as $term) {
            $like = '%'.$term.'%';
            $files->where(fn (Builder $query) => $query
                ->where(fn (Builder $own) => $ownFields($own, $like))
                ->orWhereIn('folder_id', $this->foldersInPathOf($term)));
        }

        // At least one term must hit the file itself, so a folder name alone never lists that folder's whole content.
        $files->where(function (Builder $query) use ($terms, $ownFields): void {
            foreach ($terms as $term) {
                $query->orWhere(fn (Builder $own) => $ownFields($own, '%'.$term.'%'));
            }
        });
    }

    /**
     * @param  Builder<Folder>  $folders
     * @param  list<string>  $terms
     */
    public function whereFoldersMatch(Builder $folders, array $terms): void
    {
        foreach ($terms as $term) {
            $folders->whereIn('id', $this->foldersInPathOf($term));
        }

        $folders->where(function (Builder $query) use ($terms): void {
            foreach ($terms as $term) {
                $query->orWhere('name', 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * @return list<int>
     */
    private function foldersInPathOf(string $term): array
    {
        $this->folders ??= Folder::query()->get(['id', 'parent_id', 'name'])->keyBy('id');
        $matches = [];

        foreach ($this->folders as $folder) {
            for ($step = $folder; $step !== null; $step = $this->folders->get($step->parent_id ?? 0)) {
                if (mb_stripos($step->name, $term) !== false) {
                    $matches[] = $folder->id;

                    break;
                }
            }
        }

        return $matches;
    }
}
