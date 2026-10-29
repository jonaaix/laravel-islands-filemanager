<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Models\File;
use Closure;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class LocalCopy
{
    /**
     * @template TResult
     *
     * @param  Closure(string): TResult  $use
     * @return TResult|null
     */
    public function with(File $file, Closure $use): mixed
    {
        if ($file->path === null || ! $file->mediaDisk()->exists($file->path)) {
            return null;
        }

        if ($file->volume()->isLocal()) {
            return $use($file->mediaDisk()->path($file->path));
        }

        $temporary = (new TemporaryDirectory)->create();

        try {
            $local = $temporary->path(basename($file->path));
            file_put_contents($local, $file->mediaDisk()->readStream($file->path));

            return $use($local);
        } finally {
            $temporary->delete();
        }
    }
}
