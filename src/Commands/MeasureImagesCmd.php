<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Commands;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Services\LocalCopy;
use Aaix\LaravelIslandsFilemanager\Services\ThumbnailMaker;
use Illuminate\Console\Command;

class MeasureImagesCmd extends Command
{
    protected $signature = 'filemanager:measure-images';

    protected $description = 'Record size, capture date and camera of stored images that have none yet';

    public function handle(ThumbnailMaker $thumbnails, LocalCopy $copy): int
    {
        $count = 0;

        File::query()->where('mime_type', 'like', 'image/%')->whereNull('width')->each(function (File $file) use ($thumbnails, $copy, &$count): void {
            $dimensions = $copy->with($file, fn (string $path): array => $thumbnails->inspect($file, $path)) ?? [];

            if ($dimensions !== []) {
                $file->update($dimensions);
                $count++;
            }
        });

        $this->info("Measured {$count} images.");

        return self::SUCCESS;
    }
}
