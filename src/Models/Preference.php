<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Models;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Illuminate\Database\Eloquent\Model;

class Preference extends Model
{
    public function getTable(): string
    {
        return FileManager::table('preferences');
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'info_width' => 'integer',
            'tree_width' => 'integer',
        ];
    }
}
