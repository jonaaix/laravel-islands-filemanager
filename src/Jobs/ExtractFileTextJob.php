<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Jobs;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Services\LocalCopy;
use Aaix\LaravelIslandsFilemanager\Services\TextExtractor;
use Aaix\LaravelIslandsFilemanager\Services\TextIndex;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractFileTextJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Stays below the queue's retry_after (90s), or a slow text recognition would run twice.
    public int $timeout = 85;

    public function __construct(public readonly int $fileId) {}

    public function handle(TextExtractor $extractor, TextIndex $index, LocalCopy $copy): void
    {
        $file = File::query()->find($this->fileId);

        if ($file === null) {
            return;
        }

        $index->store($file, $copy->with($file, fn (string $path): ?string => $extractor->extract($file, $path)));
    }
}
