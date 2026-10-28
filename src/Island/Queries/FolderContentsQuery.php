<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Island\Queries;

use Aaix\LaravelIslandsFilemanager\Island\Presenters\ItemPresenter;
use Aaix\LaravelIslandsFilemanager\Island\State\ViewSettingStore;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Services\FileMatcher;
use Aaix\LaravelIslandsFilemanager\Services\FolderTree;
use Illuminate\Contracts\Auth\Authenticatable;

class FolderContentsQuery
{
    public function __construct(
        private readonly ItemPresenter $presenter,
        private readonly FolderTree $tree,
        private readonly ViewSettingStore $views,
        private readonly FileMatcher $matcher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function contents(?Folder $folder, Authenticatable $user): array
    {
        return [
            'folderId' => $folder?->id,
            'path' => array_map(fn (Folder $step): array => ['id' => $step->id, 'name' => $step->name], $this->tree->ancestry($folder)),
            'folders' => Folder::query()
                ->where('parent_id', $folder?->id)
                ->with('createdBy:id,name')
                ->withCount(['children', 'files'])
                ->orderBy('name')
                ->get()
                ->map(fn (Folder $child): array => $this->presenter->folder($child))
                ->all(),
            'files' => File::query()
                ->where('folder_id', $folder?->id)
                ->with('uploadedBy:id,name')
                ->withCount('comments')
                ->orderBy('name')
                ->get()
                ->map(fn (File $file): array => $this->presenter->file($file))
                ->all(),
            'view' => $this->views->for($user, $folder),
        ];
    }

    /**
     * @return list<array{id: int, parentId: ?int, name: string, depth: int}>
     */
    public function tree(): array
    {
        return array_map(
            fn (array $row): array => ['id' => $row['folder']->id, 'parentId' => $row['folder']->parent_id, 'name' => $row['folder']->name, 'depth' => $row['depth']],
            $this->tree->flattened(),
        );
    }

    private const int SNIPPET_BEFORE = 30;

    private const int SNIPPET_AFTER = 90;

    /**
     * @return array{folders: list<array<string, mixed>>, files: list<array<string, mixed>>, contentFiles: list<array<string, mixed>>}
     */
    public function search(?Folder $within, string $query, int $limit = 200): array
    {
        $terms = $this->matcher->terms($query);
        $scope = $within === null ? null : $this->tree->descendantIds($within);
        $names = Folder::query()->pluck('name', 'id');
        $parents = Folder::query()->pluck('parent_id', 'id');

        $path = function (?int $folderId) use ($names, $parents): array {
            $steps = [];

            for (; $folderId !== null; $folderId = $parents[$folderId] ?? null) {
                array_unshift($steps, $names[$folderId]);
            }

            return $steps;
        };

        $folderQuery = Folder::query()->with('createdBy:id,name')->withCount(['children', 'files']);
        $this->matcher->whereFoldersMatch($folderQuery, $terms);
        $folders = $folderQuery
            ->when($scope, fn ($query) => $query->whereIn('parent_id', $scope))
            ->when($within, fn ($query) => $query->whereKeyNot($within->id))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Folder $folder): array => [...$this->presenter->folder($folder), 'parentId' => $folder->parent_id, 'path' => $path($folder->parent_id)])
            ->all();

        $fileQuery = File::query()->with('uploadedBy:id,name')->withCount('comments');
        $this->matcher->whereFilesMatch($fileQuery, $terms, withContent: false);
        $files = $fileQuery
            ->when($scope, fn ($query) => $query->whereIn('folder_id', $scope))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (File $file): array => [...$this->presenter->file($file), 'parentId' => $file->folder_id, 'path' => $path($file->folder_id)])
            ->all();

        $contentQuery = File::query()->with(['uploadedBy:id,name', 'content'])->withCount('comments');
        $this->matcher->whereFilesMatch($contentQuery, $terms, withContent: true);
        $contentFiles = $contentQuery
            ->has('content')
            ->whereKeyNot(array_column($files, 'id'))
            ->when($scope, fn ($query) => $query->whereIn('folder_id', $scope))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (File $file): array => [
                ...$this->presenter->file($file),
                'parentId' => $file->folder_id,
                'path' => $path($file->folder_id),
                'snippet' => $this->snippet($file->content->text, $terms),
            ])
            ->all();

        return ['folders' => $folders, 'files' => $files, 'contentFiles' => $contentFiles];
    }

    /**
     * @param  list<string>  $terms
     */
    private function snippet(string $text, array $terms): string
    {
        $found = collect($terms)->map(fn (string $term): array => [$term, mb_stripos($text, $term)])->first(fn (array $hit): bool => $hit[1] !== false) ?? [$terms[0] ?? '', 0];
        [$term, $position] = $found;
        $start = max(0, $position - self::SNIPPET_BEFORE);
        $excerpt = mb_substr($text, $start, $position - $start + mb_strlen($term) + self::SNIPPET_AFTER);

        return ($start > 0 ? '…' : '').trim($excerpt).($start + mb_strlen($excerpt) < mb_strlen($text) ? '…' : '');
    }
}
