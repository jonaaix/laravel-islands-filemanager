<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Search;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Services\FileMatcher;
use Aaix\LaravelIslandsFilemanager\Services\FolderTree;
use Aaix\LaravelIslandsSearch\Contracts\SearchSource;
use Aaix\LaravelIslandsSearch\SearchHit;
use Illuminate\Contracts\Auth\Authenticatable;

class FileSearchSource implements SearchSource
{
    public function __construct(
        private readonly FolderTree $tree,
        private readonly FileMatcher $matcher,
    ) {}

    public function key(): string
    {
        return 'files';
    }

    public function label(): string
    {
        return __('Files');
    }

    public function isVisibleTo(Authenticatable $user): bool
    {
        return FileManager::allows($user);
    }

    public function search(string $query, int $limit): array
    {
        $terms = $this->matcher->terms($query);

        $folderQuery = Folder::query()->with('parent');
        $this->matcher->whereFoldersMatch($folderQuery, $terms);
        $folders = $folderQuery
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Folder $folder): SearchHit => new SearchHit(
                title: $folder->name,
                url: FileManager::route('index', ['folder' => $folder->id]),
                subtitle: $this->location($folder->parent),
                icon: 'o-folder',
            ));

        $fileQuery = File::query()->with('folder');
        $this->matcher->whereFilesMatch($fileQuery, $terms, withContent: false);
        $files = $fileQuery
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (File $file): SearchHit => new SearchHit(
                title: $file->name,
                url: FileManager::route('index', ['folder' => $file->folder_id, 'file' => $file->id, 'preview' => 1]),
                subtitle: $this->location($file->folder),
                icon: 'o-document',
            ));

        return $folders->concat($files)->take($limit)->values()->all();
    }

    private function location(?Folder $folder): string
    {
        return implode(' › ', [__('Files'), ...array_map(fn (Folder $step): string => $step->name, $this->tree->ancestry($folder))]);
    }
}
