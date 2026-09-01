<?php

namespace App\Contracts\Content;

use App\Models\ChannelVariant;
use App\Models\ManualPublicationAccount;

interface PlatformAdapter
{
    /** @return array<string, mixed> */
    public function capabilities(): array;

    /** @return array{ok: bool, blockers: list<array<string, mixed>>, context: array<string, mixed>} */
    public function preflight(ManualPublicationAccount $account, array $context = []): array;

    /** @return array<string, mixed> */
    public function buildPayload(ChannelVariant $variant, ManualPublicationAccount $account): array;

    /**
     * Prepare a safe draft operation. Browser-assisted adapters must never submit
     * the final publish action from this method.
     *
     * @return array<string, mixed>
     */
    public function fillDraft(ChannelVariant $variant, ManualPublicationAccount $account): array;

    /** @return array<string, mixed> */
    public function readback(ChannelVariant $variant, ManualPublicationAccount $account): array;

    /** @return array<string, mixed> */
    public function normalizeReceipt(array $receipt, ChannelVariant $variant, ManualPublicationAccount $account): array;
}
