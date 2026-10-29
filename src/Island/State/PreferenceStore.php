<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Island\State;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Models\Preference;
use Illuminate\Contracts\Auth\Authenticatable;

class PreferenceStore
{
    public const int MIN_INFO_WIDTH = 240;

    public const int MAX_INFO_WIDTH = 640;

    public const int DEFAULT_INFO_WIDTH = 288;

    public const int MIN_TREE_WIDTH = 160;

    public const int MAX_TREE_WIDTH = 480;

    public const int DEFAULT_TREE_WIDTH = 224;

    public const int MAX_TABS = 20;

    /**
     * @return array{infoWidth: int, treeWidth: int, tabs: list<array{volume: string, folderId: ?int}>, activeTab: int}
     */
    public function for(Authenticatable $user): array
    {
        $preference = Preference::query()->where('user_id', $user->getAuthIdentifier())->first();
        $tabs = $this->openableTabs($user, $preference->tabs ?? []);

        return [
            'infoWidth' => $preference->info_width ?? self::DEFAULT_INFO_WIDTH,
            'treeWidth' => $preference->tree_width ?? self::DEFAULT_TREE_WIDTH,
            'tabs' => $tabs,
            'activeTab' => min($preference->active_tab ?? 0, max(0, count($tabs) - 1)),
        ];
    }

    /**
     * @param  list<array{volume: string, folder_id: ?int}>  $tabs
     */
    public function saveTabs(Authenticatable $user, array $tabs, int $activeTab): void
    {
        Preference::query()->updateOrCreate(['user_id' => $user->getAuthIdentifier()], ['tabs' => $tabs, 'active_tab' => $activeTab]);
    }

    /**
     * @param  array<int, array{volume?: string, folder_id?: ?int}>  $tabs
     * @return list<array{volume: string, folderId: ?int}>
     */
    private function openableTabs(Authenticatable $user, array $tabs): array
    {
        $volumes = FileManager::volumesFor($user);
        $folderIds = array_filter(array_column($tabs, 'folder_id'));
        $existing = Folder::query()->whereKey($folderIds)->pluck('volume', 'id');

        return array_values(array_map(
            fn (array $tab): array => ['volume' => $tab['volume'], 'folderId' => $tab['folder_id'] ?? null],
            array_filter($tabs, fn (array $tab): bool => isset($volumes[$tab['volume'] ?? ''])
                && (($tab['folder_id'] ?? null) === null || ($existing[$tab['folder_id']] ?? null) === $tab['volume'])),
        ));
    }

    /**
     * @param  array{info_width?: int, tree_width?: int}  $widths
     */
    public function saveWidths(Authenticatable $user, array $widths): void
    {
        Preference::query()->updateOrCreate(['user_id' => $user->getAuthIdentifier()], $widths);
    }

    /**
     * @return array<string, array{min: int, max: int, default: int}>
     */
    public static function limits(): array
    {
        return [
            'info' => ['min' => self::MIN_INFO_WIDTH, 'max' => self::MAX_INFO_WIDTH, 'default' => self::DEFAULT_INFO_WIDTH],
            'tree' => ['min' => self::MIN_TREE_WIDTH, 'max' => self::MAX_TREE_WIDTH, 'default' => self::DEFAULT_TREE_WIDTH],
        ];
    }
}
