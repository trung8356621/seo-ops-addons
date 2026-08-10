<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\Content\DataTransfer\SerpRankResult;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankSnapshot;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection;

final class KeywordRankSnapshotWriter
{
    public function persist(
        ?int $siteId,
        int $keywordId,
        SeoSerpProviderConnection $connection,
        SerpRankResult $result,
        ?int $runId = null,
        ?int $rankGroupId = null,
        ?int $rankGroupItemId = null,
    ): KeywordRankSnapshot {
        $organicPayload = array_map(static fn ($item): array => [
            'position' => $item->position,
            'title' => $item->title,
            'link' => $item->link,
            'displayed_link' => $item->displayedLink,
            'snippet' => $item->snippet,
        ], $result->organicResults);

        return KeywordRankSnapshot::query()->create([
            'site_id' => $siteId,
            'rank_group_id' => $rankGroupId,
            'rank_group_item_id' => $rankGroupItemId,
            'keyword_id' => $keywordId,
            'provider' => $result->provider,
            'connection_id' => (int) $connection->id,
            'location' => $result->location,
            'language' => $result->language,
            'country' => $result->country,
            'device' => $result->device,
            'position' => $result->trackedDomainBestPosition,
            'ranking_url' => $result->trackedUrl,
            'search_volume' => null,
            'allintitle' => null,
            'checked_at' => $result->checkedAt,
            'run_id' => $runId,
            'request_status' => $result->status,
            'duration_ms' => $result->durationMs,
            'error_message' => $result->errorMessage,
            'metadata' => [
                'organic_results' => $organicPayload,
                'result_count' => $result->resultCount,
                'provider_metadata' => $result->metadata,
            ],
        ]);
    }
}
