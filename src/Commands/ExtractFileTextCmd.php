<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Commands;

use Aaix\LaravelIslandsFilemanager\Jobs\ExtractFileTextJob;
use Aaix\LaravelIslandsFilemanager\Models\File;
use Aaix\LaravelIslandsFilemanager\Services\TextExtractor;
use Illuminate\Console\Command;

class ExtractFileTextCmd extends Command
{
    protected $signature = 'documents:extract-text {--all : Extract again for files that already have searchable text}';

    protected $description = 'Make the text inside stored files searchable';

    public function handle(TextExtractor $extractor): int
    {
        $count = 0;

        File::query()
            ->unless($this->option('all'), fn ($query) => $query->doesntHave('content'))
            ->each(function (File $file) use ($extractor, &$count): void {
                if ($extractor->supports($file)) {
                    ExtractFileTextJob::dispatchSync($file->id);
                    $count++;
                }
            });

        $this->info("Extracted text from {$count} files.");

        return self::SUCCESS;
    }
}
