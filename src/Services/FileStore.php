<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Jobs\ExtractFileTextJob;
use Aaix\LaravelIslandsFilemanager\Location;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;

class FileStore
{
    private const int MIN_TEXT_WITHOUT_RECOGNITION = 30;

    public function __construct(
        private readonly ThumbnailMaker $thumbnails,
        private readonly TextExtractor $extractor,
        private readonly TextIndex $index,
        private readonly FilePlacement $placement,
        private readonly Fingerprints $fingerprints,
    ) {}

    public function store(UploadedFile $upload, Location $at, Authenticatable $user): File
    {
        $name = $upload->getClientOriginalName();

        $file = File::query()->create([
            'volume' => $at->volume->key,
            'folder_id' => $at->folderId(),
            'name' => $name,
            'mime_type' => $upload->getMimeType() ?? 'application/octet-stream',
            'size' => $upload->getSize() ?: 0,
            'uploaded_by_id' => $user->getAuthIdentifier(),
        ]);

        $path = $this->placement->pathForNewFile($file, $at, $name);
        $file->mediaDisk()->putFileAs(dirname($path) === '.' ? '' : dirname($path), $upload, basename($path));
        $file->update(['path' => $path, 'name' => $at->volume->isDirect() ? basename($path) : $name]);
        $this->fingerprints->refresh($file);
        $local = (string) $upload->getRealPath();
        $file->update(['thumbnail_path' => $this->thumbnails->make($file, $local)]);

        if (config('filemanager.extract_text')) {
            $text = $this->extractor->extract($file, $local, recognise: false);
            $this->index->store($file, $text);

            if ($this->extractor->needsRecognition($file) && mb_strlen((string) $text) < self::MIN_TEXT_WITHOUT_RECOGNITION) {
                ExtractFileTextJob::dispatch($file->id);
            }
        }

        return $file;
    }
}
