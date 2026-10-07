<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicGroupingRebuildMode;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicFromKeywordGroupMaterializer;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicGroupingProviderMode;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicReclusterUiState;

/**
 * User-facing Topic rebuild from persisted Keyword Groups.
 *
 * Does NOT call semantic Topic/Keyword-Group grouping providers.
 * Legacy {@see ReclusterSiteTopicsJob} remains for internal/compat only.
 */
final class RebuildTopicsFromKeywordGroupsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $siteId,
        public readonly string $rebuildMode = TopicGroupingRebuildMode::PRESERVE_EXISTING,
    ) {
        $this->onQueue('seo');
    }

    public function uniqueId(): string
    {
        return 'topic-from-keyword-groups:'.$this->siteId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('topic-from-keyword-groups:'.$this->siteId))
                ->releaseAfter(30)
                ->expireAfter(3600),
        ];
    }

    public function handle(TopicFromKeywordGroupMaterializer $materializer): void
    {
        $rebuildMode = TopicGroupingRebuildMode::normalize($this->rebuildMode);
        // Use legacy mode so UI mutation lock applies while running (not semantic analyze).
        TopicReclusterUiState::put($this->siteId, [
            'status' => TopicReclusterUiState::STATUS_QUEUED,
            'site_id' => $this->siteId,
            'mode' => TopicGroupingProviderMode::LEGACY,
            'algorithm_version' => TopicFromKeywordGroupMaterializer::ALGORITHM,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'metrics' => ['rebuild_mode' => $rebuildMode, 'source' => TopicFromKeywordGroupMaterializer::ALGORITHM],
            'error' => null,
            'failure_reason' => null,
        ]);

        TopicReclusterUiState::markRunning($this->siteId, TopicFromKeywordGroupMaterializer::ALGORITHM);

        $result = $materializer->materialize($this->siteId, $rebuildMode);
        if ($result->ok) {
            TopicReclusterUiState::markSucceeded(
                $this->siteId,
                $result->metrics,
                TopicFromKeywordGroupMaterializer::ALGORITHM,
            );

            return;
        }

        TopicReclusterUiState::markFailed(
            $this->siteId,
            $result->error ?? 'topic_from_groups_failed',
            $result->metrics,
            'topic_from_groups_failed',
            TopicFromKeywordGroupMaterializer::ALGORITHM,
        );
    }
}
