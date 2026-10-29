<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Island;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Aaix\LaravelIslandsFilemanager\Island\Queries\FolderContentsQuery;
use Aaix\LaravelIslandsFilemanager\Island\State\PreferenceStore;
use Aaix\LaravelIslandsFilemanager\Island\State\ViewSettingStore;
use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsSearch\Support\HeroiconSet;
use Illuminate\Contracts\Auth\Authenticatable;

class FileManagerProps
{
    private const string ID = '__ID__';

    public function __construct(
        private readonly FolderContentsQuery $contents,
        private readonly HeroiconSet $icons,
        private readonly PreferenceStore $preferences,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Authenticatable $user, Location $at, ?File $file, bool $preview = false): array
    {
        $route = fn (string $name, string $parameter): string => FileManager::route($name, [$parameter => self::ID]);

        return [
            'pageUrl' => FileManager::route('index'),
            'initial' => [
                'contents' => $this->contents->contents($at, $user),
                'fileId' => $file?->id,
                'preview' => $file !== null && $preview,
                'volumes' => $this->contents->tree($user),
            ],
            'tileSize' => ['min' => ViewSettingStore::MIN_TILE, 'max' => ViewSettingStore::MAX_TILE],
            'preferences' => $this->preferences->for($user),
            'paneWidths' => PreferenceStore::limits(),
            'endpoints' => [
                'contents' => FileManager::route('contents'),
                'search' => FileManager::route('search'),
                'view' => FileManager::route('view'),
                'preferences' => FileManager::route('preferences'),
                'folders' => FileManager::route('folders.index'),
                'foldersStore' => FileManager::route('folders.store'),
                'foldersStoreWithItems' => FileManager::route('folders.store-with-items'),
                'foldersRename' => $route('folders.rename', 'folder'),
                'itemsMove' => FileManager::route('items.move'),
                'itemsDestroy' => FileManager::route('items.destroy'),
                'itemsZip' => FileManager::route('items.zip'),
                'filesStore' => FileManager::route('files.store'),
                'filesShow' => $route('files.show', 'file'),
                'filesUpdate' => $route('files.update', 'file'),
                'commentsStore' => $route('comments.store', 'file'),
                'commentsDestroy' => $route('comments.destroy', 'comment'),
            ],
            'icons' => $this->icons->build([
                'o-folder', 'o-folder-plus', 'o-folder-open', 'o-document', 'o-document-text', 'o-photo', 'o-arrow-up-tray', 'o-arrow-down-tray',
                'o-pencil-square', 'o-trash', 'o-arrows-right-left', 'o-chat-bubble-left', 'o-chevron-right', 'o-chevron-left', 'o-chevron-down',
                'o-chevron-up', 'o-x-mark', 'o-home', 'o-arrow-top-right-on-square', 'o-paper-airplane', 'o-eye', 'o-archive-box-arrow-down',
                'o-question-mark-circle', 'o-magnifying-glass', 'o-information-circle', 'o-ellipsis-horizontal', 'o-folder-arrow-down', 'o-server-stack',
            ]),
        ];
    }
}
