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

class FileContentSearchSource implements SearchSource
{
    public function __construct(
        private readonly FolderTree $tree,
        private readonly FileMatcher $matcher,
    ) {}

    public function key(): string
    {
        return 'file-contents';
    }

    public function label(): string
    {
        return __('Inside files');
    }

    public function isVisibleTo(Authenticatable $user): bool
    {
        return FileManager::allows($user);
    }

    public function search(string $query, int $limit): array
    {
        $terms = $this->matcher->terms($query);
        $volumes = array_keys(FileManager::volumesFor(auth()->user()));

        $byName = File::query();
        $this->matcher->whereFilesMatch($byName, $terms, withContent: false);

        $files = File::query()->with('folder')->whereIn('volume', $volumes)->has('content');
        $this->matcher->whereFilesMatch($files, $terms, withContent: true);

        return $files
            ->whereNotIn('id', $byName->select('id'))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (File $file): SearchHit => new SearchHit(
                title: $file->name,
                url: FileManager::route('index', ['volume' => $file->getAttribute('volume'), 'folder' => $file->folder_id, 'file' => $file->id, 'preview' => 1]),
                subtitle: implode(' › ', [__($file->volume()->name), ...array_map(fn (Folder $step): string => $step->name, $this->tree->ancestry($file->folder))]),
                icon: 'o-document-magnifying-glass',
            ))
            ->all();
    }
}
