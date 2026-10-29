<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Volume;
use Throwable;

class Fingerprints
{
    public function of(Volume $volume, string $path): string
    {
        $disk = $volume->filesystem();

        try {
            if ($volume->isLocal()) {
                return 'inode:'.fileinode($disk->path($path)).':'.$disk->size($path);
            }

            $etag = $disk->checksum($path, ['checksum_algo' => 'etag']);

            if (is_string($etag) && $etag !== '') {
                return 'etag:'.trim($etag, '"').':'.$disk->size($path);
            }
        } catch (Throwable) {
            // Not every driver knows checksums; size and time below still tell most files apart.
        }

        return 'stat:'.$disk->size($path).':'.$disk->lastModified($path);
    }

    public function refresh(File $file): void
    {
        $volume = $file->volume();

        if ($volume->isDirect() && $file->path !== null && $volume->filesystem()->exists($file->path)) {
            $file->update(['fingerprint' => $this->of($volume, $file->path)]);
        }
    }
}
