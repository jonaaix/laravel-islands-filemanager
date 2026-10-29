<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Services;

use Aaix\LaravelIslandsFilemanager\Models\File;

class TextIndex
{
    public function store(File $file, ?string $text): void
    {
        $path = $file->volume()->helperPath('text', $file->mediaHash(), 'txt');

        if ($text === null) {
            $file->mediaDisk()->delete($path);
            $file->content()->delete();

            return;
        }

        $file->mediaDisk()->put($path, $text);
        $file->content()->updateOrCreate([], ['text' => $text]);
    }
}
