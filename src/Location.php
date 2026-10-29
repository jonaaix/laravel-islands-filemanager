<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager;

use Aaix\LaravelIslandsFilemanager\Models\Folder;

final readonly class Location
{
    public function __construct(
        public Volume $volume,
        public ?Folder $folder = null,
    ) {}

    public static function of(Folder $folder): self
    {
        return new self($folder->volume(), $folder);
    }

    public function folderId(): ?int
    {
        return $this->folder?->id;
    }

    public function isSameVolume(string $volume): bool
    {
        return $this->volume->key === $volume;
    }
}
