<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Models\Concerns;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

trait HasMediaDirectory
{
    public static function bootHasMediaDirectory(): void
    {
        static::deleted(function (self $model): void {
            if (! FileManager::volume($model->getAttribute('volume'))->isDirect()) {
                $model->mediaDisk()->deleteDirectory($model->mediaDirectory());
            }
        });
    }

    public function mediaDisk(): Filesystem
    {
        return FileManager::volume($this->getAttribute('volume'))->filesystem();
    }

    public function mediaHash(): string
    {
        $salt = config('filemanager.hash_salt');

        if (! is_string($salt) || $salt === '') {
            throw new RuntimeException('The file manager needs a hash salt (filemanager.hash_salt).');
        }

        return md5($salt.$this->getKey());
    }

    public function mediaDirectory(): string
    {
        $hash = $this->mediaHash();

        return implode('/', [
            app()->environment(),
            $this->getTable(),
            substr($hash, 0, 2),
            substr($hash, 2, 2),
            substr($hash, 4, 2),
            substr($hash, 6),
        ]);
    }

    public function mediaPath(string $fileName): string
    {
        return $this->mediaDirectory().'/'.$fileName;
    }
}
