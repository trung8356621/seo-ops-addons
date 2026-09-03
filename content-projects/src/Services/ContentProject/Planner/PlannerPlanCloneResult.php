<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner;

/**
 * @phpstan-type DestinationResult array{
 *   site_id: int,
 *   domain: string,
 *   status: string,
 *   note_items: list<array<string, mixed>>,
 *   content_type: string,
 *   topic_count: int,
 *   dna_count: int,
 *   target_total: int,
 *   warnings: list<string>,
 *   skipped_topics: list<string>
 * }
 */
final class PlannerPlanCloneResult
{
    /**
     * @param  list<DestinationResult>  $destinations
     */
    public function __construct(
        public readonly int $sourceSiteId,
        public readonly string $sourceDomain,
        public readonly string $mode,
        public readonly int $sourceTopicCount,
        public readonly int $sourceDnaCount,
        public readonly int $sourceTargetTotal,
        public readonly array $destinations,
        public readonly string $correlationId,
    ) {}

    public function copiedCount(): int
    {
        return count(array_filter(
            $this->destinations,
            static fn (array $row): bool => ($row['status'] ?? '') === 'copied',
        ));
    }

    public function skippedCount(): int
    {
        return count(array_filter(
            $this->destinations,
            static fn (array $row): bool => ($row['status'] ?? '') === 'skipped',
        ));
    }

    public function failedCount(): int
    {
        return count(array_filter(
            $this->destinations,
            static fn (array $row): bool => ($row['status'] ?? '') === 'failed',
        ));
    }

    public function warningTopicCount(): int
    {
        $n = 0;
        foreach ($this->destinations as $row) {
            $n += count($row['skipped_topics'] ?? []);
        }

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'correlation_id' => $this->correlationId,
            'source_site_id' => $this->sourceSiteId,
            'source_domain' => $this->sourceDomain,
            'mode' => $this->mode,
            'source_topic_count' => $this->sourceTopicCount,
            'source_dna_count' => $this->sourceDnaCount,
            'source_target_total' => $this->sourceTargetTotal,
            'copied' => $this->copiedCount(),
            'skipped' => $this->skippedCount(),
            'failed' => $this->failedCount(),
            'topics_needing_review' => $this->warningTopicCount(),
            'destinations' => $this->destinations,
            'summary_message' => sprintf(
                'Đã sao chép: %d domain · Đã bỏ qua: %d · Cần kiểm tra: %d Topic · Thất bại: %d',
                $this->copiedCount(),
                $this->skippedCount(),
                $this->warningTopicCount(),
                $this->failedCount(),
            ),
        ];
    }
}
