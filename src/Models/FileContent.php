<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Models;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileContent extends Model
{
    public function getTable(): string
    {
        return FileManager::table('file_contents');
    }

    protected $guarded = [];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }
}
