<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Island\State;

use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Aaix\LaravelIslandsFilemanager\Models\ViewSetting;
use Illuminate\Contracts\Auth\Authenticatable;

class ViewSettingStore
{
    public const array MODES = ['list', 'grid'];

    public const array SORTS = ['name', 'owner', 'modified', 'added', 'size', 'kind'];

    public const array DIRECTIONS = ['asc', 'desc'];

    public const int MIN_TILE = 96;

    public const int MAX_TILE = 320;

    private const int DEFAULT_TILE = 160;

    /**
     * @return array{mode: string, tileSize: int, sortBy: string, sortDirection: string}
     */
    public function for(Authenticatable $user, ?Folder $folder): array
    {
        $setting = ViewSetting::query()->where('user_id', $user->getAuthIdentifier())->where('folder_id', $folder?->id)->first();

        return [
            'mode' => $setting->mode ?? 'list',
            'tileSize' => $setting->tile_size ?? self::DEFAULT_TILE,
            'sortBy' => $setting->sort_by ?? 'name',
            'sortDirection' => $setting->sort_direction ?? 'asc',
        ];
    }

    /**
     * @param  array{mode: string, tile_size: int, sort_by: string, sort_direction: string}  $view
     */
    public function save(Authenticatable $user, ?Folder $folder, array $view): void
    {
        ViewSetting::query()->updateOrCreate(['user_id' => $user->getAuthIdentifier(), 'folder_id' => $folder?->id], $view);
    }
}
