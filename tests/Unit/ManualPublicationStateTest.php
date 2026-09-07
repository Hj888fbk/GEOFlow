<?php

namespace Tests\Unit;

use App\Models\ManualPublication;
use App\Models\ManualPublicationBatch;
use PHPUnit\Framework\TestCase;

class ManualPublicationStateTest extends TestCase
{
    public function test_status_machine_exposes_only_supported_next_states(): void
    {
        $this->assertSame(
            [ManualPublication::STATUS_READY, ManualPublication::STATUS_CANCELLED],
            ManualPublication::allowedNextStatuses(ManualPublication::STATUS_DRAFT),
        );
        $this->assertSame(
            [ManualPublication::STATUS_IN_PROGRESS, ManualPublication::STATUS_CANCELLED],
            ManualPublication::allowedNextStatuses(ManualPublication::STATUS_READY),
        );
        $this->assertContains(
            ManualPublication::STATUS_COMPLETED,
            ManualPublication::allowedNextStatuses(ManualPublication::STATUS_IN_PROGRESS),
        );
        $this->assertSame([], ManualPublication::allowedNextStatuses(ManualPublication::STATUS_COMPLETED));
    }

    public function test_only_failed_skipped_and_cancelled_states_can_reopen_to_ready(): void
    {
        foreach (ManualPublication::REOPENABLE_STATUSES as $status) {
            $publication = new ManualPublication(['status' => $status]);
            $this->assertTrue($publication->isReopenTransition(ManualPublication::STATUS_READY));
        }

        $completed = new ManualPublication(['status' => ManualPublication::STATUS_COMPLETED]);
        $this->assertFalse($completed->isReopenTransition(ManualPublication::STATUS_READY));
    }

    public function test_batch_status_aggregates_active_pending_and_terminal_work_orders(): void
    {
        $this->assertSame(ManualPublicationBatch::STATUS_ACTIVE, ManualPublicationBatch::statusFromPublications([
            ManualPublication::STATUS_DRAFT_FILLED,
            ManualPublication::STATUS_READY,
        ], ManualPublicationBatch::STATUS_PENDING_PLATFORM));
        $this->assertSame(ManualPublicationBatch::STATUS_PENDING_VERIFICATION, ManualPublicationBatch::statusFromPublications([
            ManualPublication::STATUS_OUTCOME_UNKNOWN,
            ManualPublication::STATUS_COMPLETED,
        ], ManualPublicationBatch::STATUS_ACTIVE));
        $this->assertSame(ManualPublicationBatch::STATUS_PENDING_PLATFORM, ManualPublicationBatch::statusFromPublications([
            ManualPublication::STATUS_READY,
            ManualPublication::STATUS_COMPLETED,
        ], ManualPublicationBatch::STATUS_ACTIVE));
        $this->assertSame(ManualPublicationBatch::STATUS_COMPLETED, ManualPublicationBatch::statusFromPublications([
            ManualPublication::STATUS_COMPLETED,
        ], ManualPublicationBatch::STATUS_ACTIVE));
        $this->assertSame(ManualPublicationBatch::STATUS_CANCELLED, ManualPublicationBatch::statusFromPublications([
            ManualPublication::STATUS_CANCELLED,
        ], ManualPublicationBatch::STATUS_ACTIVE));
        $this->assertSame(ManualPublicationBatch::STATUS_FAILED, ManualPublicationBatch::statusFromPublications([
            ManualPublication::STATUS_COMPLETED,
            ManualPublication::STATUS_FAILED,
        ], ManualPublicationBatch::STATUS_ACTIVE));
    }
}
