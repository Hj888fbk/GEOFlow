<?php

namespace App\Services\Content;

use App\Models\ContentSourceFile;
use App\Models\ContentTask;
use App\Models\EvidenceClaim;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class HengjiaDailyContentPlanner
{
    /** @return Collection<int,ContentTask> */
    public function plan(?Carbon $date = null, ?int $limit = null, ?int $adminId = null): Collection
    {
        $date ??= now();
        $limits = (array) config('hengjia-content.daily_candidate_limit', ['min' => 3, 'max' => 6]);
        $max = min(6, max(3, $limit ?? (int) ($limits['max'] ?? 6)));
        $definitions = collect((array) config('hengjia-content.daily_candidates', []))
            ->sortBy([['priority', 'asc'], ['key', 'asc']])
            ->take($max);

        return $definitions->map(function (array $definition) use ($date, $adminId): ContentTask {
            $candidateKey = $date->toDateString().':'.(string) $definition['key'];
            $existingCandidateId = ContentTask::query()
                ->where('candidate_key', $candidateKey)
                ->value('id');
            $blockers = $this->evidenceBlockers(
                (array) ($definition['required_claim_types'] ?? []),
                is_numeric($existingCandidateId) ? (int) $existingCandidateId : null,
            );
            if ($this->hasOverlappingIntent($definition, $candidateKey)) {
                $blockers[] = [
                    'code' => 'existing_page_or_task_overlap',
                    'message' => '已存在相同页面职责和核心意图，优先更新现有页面或任务。',
                ];
            }

            $payload = [
                'candidate_key' => $candidateKey,
                'product_key' => 'rubber-joint',
                'title' => (string) $definition['title'],
                'audience' => (string) $definition['audience'],
                'intent' => (string) $definition['intent'],
                'page_role' => (string) $definition['page_role'],
                'primary_keyword' => (string) $definition['primary_keyword'],
                'secondary_keywords' => array_values((array) ($definition['secondary_keywords'] ?? [])),
                'target_channels' => array_map(
                    static fn (mixed $channel): array => ['channel_key' => (string) $channel, 'account_id' => null],
                    array_values((array) $definition['target_channels']),
                ),
                'priority' => (int) ($definition['priority'] ?? 3),
                'due_on' => $date->toDateString(),
                'status' => $blockers === [] ? ContentTask::STATUS_READY : ContentTask::STATUS_BLOCKED,
                'review_status' => ContentTask::REVIEW_PENDING,
                'blockers' => $blockers,
                'input_hash' => $this->hash(['date' => $date->toDateString(), 'definition' => $definition]),
                'created_by_admin_id' => $adminId,
            ];

            return ContentTask::query()->updateOrCreate(['candidate_key' => $candidateKey], $payload);
        })->values();
    }

    /** @param list<string> $types @return list<array{code:string,message:string}> */
    private function evidenceBlockers(array $types, ?int $contentTaskId = null): array
    {
        $blockers = [];
        foreach ($types as $type) {
            $claims = EvidenceClaim::query()
                ->with('sourceFile')
                ->where('claim_type', $type)
                ->where(function ($query) use ($contentTaskId): void {
                    $query->whereNull('content_task_id');
                    if ($contentTaskId !== null) {
                        $query->orWhere('content_task_id', $contentTaskId);
                    }
                })
                ->whereIn('evidence_status', [
                    ContentSourceFile::STATUS_PUBLIC_RECORD_VERIFIED,
                    ContentSourceFile::STATUS_INTERNAL_CONFIRMED,
                ])
                ->where('public_permission', ContentSourceFile::PERMISSION_PUBLISHABLE)
                ->whereNotNull('reviewed_at')
                ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString()))
                ->whereHas('sourceFile', fn ($query) => $query->approvedForPublication())
                ->get();

            $available = $claims->contains(function (EvidenceClaim $claim): bool {
                return $claim->isPublishableFact() && $claim->hasCompleteQualificationPayload();
            });
            if (! $available) {
                $blockers[] = [
                    'code' => 'missing_publishable_evidence:'.$type,
                    'message' => '缺少已审核且允许公开的'.self::claimTypeLabel((string) $type).'证据。',
                ];
            }
        }

        return $blockers;
    }

    /** @param array<string,mixed> $definition */
    private function hasOverlappingIntent(array $definition, string $candidateKey): bool
    {
        return ContentTask::query()
            ->where('candidate_key', '!=', $candidateKey)
            ->where('page_role', (string) ($definition['page_role'] ?? ''))
            ->where('primary_keyword', (string) ($definition['primary_keyword'] ?? ''))
            ->whereNotIn('status', [ContentTask::STATUS_CANCELLED, ContentTask::STATUS_COMPLETED])
            ->exists();
    }

    /** @param array<string,mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function claimTypeLabel(string $type): string
    {
        return match ($type) {
            EvidenceClaim::TYPE_COMPANY => '企业主体',
            EvidenceClaim::TYPE_QUALIFICATION => '资质',
            EvidenceClaim::TYPE_PRODUCT => '产品参数',
            EvidenceClaim::TYPE_PRODUCTION => '生产能力',
            EvidenceClaim::TYPE_INSPECTION => '检测能力',
            EvidenceClaim::TYPE_CASE => '案例',
            EvidenceClaim::TYPE_IMAGE_RIGHTS => '图片授权',
            default => $type,
        };
    }
}
