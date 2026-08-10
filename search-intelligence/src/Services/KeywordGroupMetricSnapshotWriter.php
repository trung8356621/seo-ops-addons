<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\Content\DataTransfer\SerpAllintitleResult;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordGroupMetricType;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetricStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordGroupMetricSnapshot;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection;

final class KeywordGroupMetricSnapshotWriter
{
    public function persistAllintitle(
        int $rankGroupId,
        int $rankGroupItemId,
        int $keywordId,
        SeoSerpProviderConnection $connection,
        SerpAllintitleResult $result,
        ?int $runId = null,
    ): KeywordGroupMetricSnapshot {
        $status = match ($result->status) {
            SerpAllintitleResult::STATUS_SUCCESS => KeywordMetricStatus::Success->value,
            SerpAllintitleResult::STATUS_NOT_FOUND => KeywordMetricStatus::NotFound->value,
            SerpAllintitleResult::STATUS_NOT_SUPPORTED => KeywordMetricStatus::NotSupported->value,
            default => KeywordMetricStatus::Failed->value,
        };

        return KeywordGroupMetricSnapshot::query()->create([
            'rank_group_id' => $rankGroupId,
            'rank_group_item_id' => $rankGroupItemId,
            'keyword_id' => $keywordId,
            'metric_type' => KeywordGroupMetricType::Allintitle->value,
            'provider' => $result->provider,
            'source' => null,
            'value_int' => $result->estimatedResults,
            'status' => $status,
            'error_message' => $result->errorMessage,
            'checked_at' => now(),
            'run_id' => $runId,
            'metadata' => [
                'duration_ms' => $result->durationMs,
                'provider_metadata' => $result->metadata,
            ],
        ]);
    }

    public function persistSearchVolume(
        int $rankGroupId,
        int $rankGroupItemId,
        int $keywordId,
        ?int $volume,
        string $status,
        ?string $source = null,
        ?string $errorMessage = null,
        ?int $runId = null,
    ): KeywordGroupMetricSnapshot {
        return KeywordGroupMetricSnapshot::query()->create([
            'rank_group_id' => $rankGroupId,
            'rank_group_item_id' => $rankGroupItemId,
            'keyword_id' => $keywordId,
            'metric_type' => KeywordGroupMetricType::SearchVolume->value,
            'provider' => 'dataforseo',
            'source' => $source,
            'value_int' => $volume,
            'status' => $status,
            'error_message' => $errorMessage,
            'checked_at' => now(),
            'run_id' => $runId,
            'metadata' => null,
        ]);
    }
}
