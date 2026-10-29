<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Jobs;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Services\LocalCopy;
use Aaix\LaravelIslandsFilemanager\Services\TextExtractor;
use Aaix\LaravelIslandsFilemanager\Services\TextIndex;
use Aaix\LaravelIslandsFilemanager\Services\ThumbnailMaker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractFileTextJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Stays below the queue's retry_after (90s), or a slow text recognition would run twice.
    public int $timeout = 85;

    public function __construct(
        public readonly int $fileId,
        public readonly bool $withThumbnail = false,
    ) {}

    public function handle(TextExtractor $extractor, TextIndex $index, ThumbnailMaker $thumbnails, LocalCopy $copy): void
    {
        $file = File::query()->find($this->fileId);

        if ($file === null) {
            return;
        }

        $copy->with($file, function (string $path) use ($file, $extractor, $index, $thumbnails): void {
            if ($this->withThumbnail && $file->thumbnail_path === null) {
                $file->update(['thumbnail_path' => $thumbnails->make($file, $path), ...$thumbnails->measure($file, $path)]);
            }

            if (config('filemanager.extract_text')) {
                $index->store($file, $extractor->extract($file, $path));
            }
        });
    }
}
