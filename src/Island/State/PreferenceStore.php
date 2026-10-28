<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Island\State;

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

    /**
     * @return array{infoWidth: int, treeWidth: int}
     */
    public function for(Authenticatable $user): array
    {
        $preference = Preference::query()->where('user_id', $user->getAuthIdentifier())->first();

        return [
            'infoWidth' => $preference->info_width ?? self::DEFAULT_INFO_WIDTH,
            'treeWidth' => $preference->tree_width ?? self::DEFAULT_TREE_WIDTH,
        ];
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
