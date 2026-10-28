<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Jobs\ExtractFileTextJob;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Models\Folder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;

class FileStore
{
    public function __construct(private readonly ThumbnailMaker $thumbnails) {}

    public function store(UploadedFile $upload, ?Folder $folder, Authenticatable $user): File
    {
        $name = $upload->getClientOriginalName();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: ($upload->guessExtension() ?? 'bin'));

        $file = File::query()->create([
            'folder_id' => $folder?->id,
            'name' => $name,
            'mime_type' => $upload->getMimeType() ?? 'application/octet-stream',
            'size' => $upload->getSize() ?: 0,
            'uploaded_by_id' => $user->getAuthIdentifier(),
        ]);

        $file->mediaDisk()->putFileAs($file->mediaDirectory(), $upload, 'original.'.$extension);
        $file->update(['path' => $file->mediaPath('original.'.$extension)]);
        $file->update(['thumbnail_path' => $this->thumbnails->make($file)]);

        if (config('filemanager.extract_text')) {
            ExtractFileTextJob::dispatch($file->id);
        }

        return $file;
    }
}
