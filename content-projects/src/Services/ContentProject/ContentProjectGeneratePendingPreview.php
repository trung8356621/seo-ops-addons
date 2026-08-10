<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

/**
 * Dry-run preview trước khi dispatch Generate pending.
 */
final class ContentProjectGeneratePendingPreview
{
    /**
     * @param  list<ContentProjectItemGenerationDecision>  $decisions
     */
    public function __construct(
        public readonly int $projectId,
        public readonly int $totalItems,
        public readonly array $decisions,
        public readonly bool $hasHistoricalExecution,
        public readonly bool $failClosed,
        public readonly string $failClosedReason = '',
    ) {}

    /**
     * @return list<ContentProjectItemGenerationDecision>
     */
    public function runDecisions(): array
    {
        return array_values(array_filter(
            $this->decisions,
            static fn (ContentProjectItemGenerationDecision $d): bool => $d->shouldRun(),
        ));
    }

    /**
     * @return list<ContentProjectItemGenerationDecision>
     */
    public function skipDecisions(): array
    {
        return array_values(array_filter(
            $this->decisions,
            static fn (ContentProjectItemGenerationDecision $d): bool => $d->action === ContentProjectItemGenerationDecision::ACTION_SKIP,
        ));
    }

    /**
     * @return list<ContentProjectItemGenerationDecision>
     */
    public function anomalyDecisions(): array
    {
        return array_values(array_filter(
            $this->decisions,
            static fn (ContentProjectItemGenerationDecision $d): bool => $d->action === ContentProjectItemGenerationDecision::ACTION_ANOMALY,
        ));
    }

    /**
     * @return list<int>
     */
    public function runnableTaskIds(): array
    {
        return array_map(
            static fn (ContentProjectItemGenerationDecision $d): int => $d->taskId,
            $this->runDecisions(),
        );
    }

    public function runCount(): int
    {
        return count($this->runDecisions());
    }

    public function canDispatch(): bool
    {
        return $this->runCount() > 0;
    }

    public function requiresTechnicalConfirm(): bool
    {
        return $this->failClosed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'total_items' => $this->totalItems,
            'run_count' => $this->runCount(),
            'skip_count' => count($this->skipDecisions()),
            'anomaly_count' => count($this->anomalyDecisions()),
            'has_historical_execution' => $this->hasHistoricalExecution,
            'fail_closed' => $this->failClosed,
            'fail_closed_reason' => $this->failClosedReason,
            'runnable_task_ids' => $this->runnableTaskIds(),
            'decisions' => array_map(
                static fn (ContentProjectItemGenerationDecision $d): array => $d->toArray(),
                $this->decisions,
            ),
        ];
    }
}
