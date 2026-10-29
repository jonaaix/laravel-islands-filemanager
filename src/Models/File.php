<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Models;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Aaix\LaravelIslandsFilemanager\Models\Concerns\HasMediaDirectory;
use Aaix\LaravelIslandsFilemanager\Volume;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class File extends Model
{
    use HasMediaDirectory {
        mediaDirectory as freshMediaDirectory;
    }

    public function getTable(): string
    {
        return FileManager::table('files');
    }

    protected $guarded = [];

    // A record forgotten after the file vanished from a real folder must not delete anything else on that disk.
    public bool $removesOriginal = true;

    protected static function booted(): void
    {
        static::deleted(function (self $file): void {
            $direct = $file->volume()->isDirect() && $file->removesOriginal;
            $file->mediaDisk()->delete(array_values(array_filter([
                $file->thumbnail_path,
                $file->volume()->helperPath('text', $file->mediaHash(), 'txt'),
                $direct ? $file->path : null,
            ])));
        });
    }

    public function volume(): Volume
    {
        return FileManager::volume($this->getAttribute('volume'));
    }

    public function forget(): void
    {
        $this->removesOriginal = false;
        $this->delete();
    }

    public function mediaDirectory(): string
    {
        return $this->path === null ? $this->freshMediaDirectory() : dirname($this->path);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'file_id');
    }

    public function content(): HasOne
    {
        return $this->hasOne(FileContent::class, 'file_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(FileManager::userModel(), 'uploaded_by_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
