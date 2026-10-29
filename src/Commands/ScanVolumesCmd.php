<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Commands;

use Aaix\LaravelIslandsFilemanager\FileManager;
use Aaix\LaravelIslandsFilemanager\Services\DirectScanner;
use Aaix\LaravelIslandsFilemanager\Volume;
use Illuminate\Console\Command;

class ScanVolumesCmd extends Command
{
    protected $signature = 'filemanager:scan {volume? : Only this drive}';

    protected $description = 'Pick up changes made outside the file manager on drives that show a real folder structure';

    public function handle(DirectScanner $scanner): int
    {
        $volumes = array_filter(FileManager::volumes(), fn (Volume $volume): bool => $volume->isDirect() && ($this->argument('volume') === null || $volume->key === $this->argument('volume')));

        foreach ($volumes as $volume) {
            $this->info("{$volume->name}: ".$scanner->scanVolume($volume).' folders checked.');
        }

        return self::SUCCESS;
    }
}
