<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Models;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Illuminate\Database\Eloquent\Model;

class ViewSetting extends Model
{
    public function getTable(): string
    {
        return FileManager::table('view_settings');
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tile_size' => 'integer',
        ];
    }
}
