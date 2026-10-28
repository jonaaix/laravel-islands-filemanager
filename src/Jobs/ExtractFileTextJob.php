<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Jobs;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Services\TextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractFileTextJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public readonly int $fileId) {}

    public function handle(TextExtractor $extractor): void
    {
        $file = File::query()->find($this->fileId);

        if ($file === null) {
            return;
        }

        $text = $extractor->extract($file);

        if ($text === null) {
            $file->content()->delete();

            return;
        }

        $file->content()->updateOrCreate([], ['text' => $text]);
    }
}
