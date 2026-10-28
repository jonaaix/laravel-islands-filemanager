<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Models;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folder extends Model
{
    public function getTable(): string
    {
        return FileManager::table('folders');
    }

    protected $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'folder_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(FileManager::userModel(), 'created_by_id');
    }
}
