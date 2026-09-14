<?php

namespace App\Console\Commands;

use App\Services\SelfMedia\ManualPublicationLifecycleService;
use Illuminate\Console\Command;

final class PruneManualPublicationTrashCommand extends Command
{
    protected $signature = 'geoflow:prune-manual-publication-trash';

    protected $description = 'Permanently delete manual publication records after 30 days in trash';

    public function handle(ManualPublicationLifecycleService $lifecycle): int
    {
        $pruned = $lifecycle->pruneExpired();
        $this->info(sprintf(
            'Permanently deleted %d publication batches and %d work orders.',
            $pruned['batches'],
            $pruned['publications'],
        ));

        return self::SUCCESS;
    }
}
