<?php

namespace App\Jobs;

use App\Models\ManualPublicationBatch;
use App\Services\SelfMedia\SelfMediaBatchGenerationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateSelfMediaBatchJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $batchId)
    {
        $this->onQueue('self-media');
    }

    public function uniqueId(): string
    {
        return 'self-media-batch:'.$this->batchId;
    }

    public function handle(SelfMediaBatchGenerationService $generation): void
    {
        $batch = ManualPublicationBatch::query()->find($this->batchId);
        if ($batch instanceof ManualPublicationBatch
            && in_array($batch->status, [ManualPublicationBatch::STATUS_PLANNED, ManualPublicationBatch::STATUS_FAILED], true)) {
            $generation->generate($batch);
        }
    }
}
