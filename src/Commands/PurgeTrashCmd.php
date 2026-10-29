<?php

declare(strict_types=1);

namespace Aaix\LaravelIslandsFilemanager\Commands;

use Aaix\LaravelIslandsFilemanager\Services\Trash;
use Illuminate\Console\Command;

class PurgeTrashCmd extends Command
{
    protected $signature = 'filemanager:purge-trash';

    protected $description = 'Delete items that have been in the trash longer than filemanager.trash_days';

    public function handle(Trash $trash): int
    {
        $days = Trash::retentionDays();

        if ($days === null) {
            $this->info('The trash is kept until it is emptied by hand.');

            return self::SUCCESS;
        }

        $this->info($trash->purgeOlderThan($days).' items deleted.');

        return self::SUCCESS;
    }
}
