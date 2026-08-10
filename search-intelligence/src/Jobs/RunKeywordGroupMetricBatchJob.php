<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Omnichannel\Addons\Content\DataTransfer\SerpRankRequest;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetricStatus;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankCheckRun;
use Omnichannel\Addons\SearchIntelligence\Providers\Serp\SerpRankProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroupMetricSnapshotWriter;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordSearchVolumeService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoSerpProviderConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RunKeywordGroupMetricBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  list<array{item_id: int, keyword_id: int}>  $groupItems
     */
    public function __construct(
        public int $runId,
        public int $userId,
        public string $provider,
        public string $metricType,
        public array $groupItems,
        public string $connectionHash,
        public ?string $country = null,
        public ?string $location = null,
        public ?string $language = null,
        public ?string $device = null,
    ) {}

    public function handle(
        SeoSerpProviderConnectionService $serpConnections,
        SerpRankProviderRegistry $registry,
        KeywordGroupMetricSnapshotWriter $metricWriter,
        KeywordSearchVolumeService $volumeService,
        SeoDatabaseConnectionService $databaseConnection,
    ): void {
        $databaseConnection->bootstrapByHash($this->connectionHash);

        $run = KeywordRankCheckRun::query()->find($this->runId);
        if ($run === null || in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }

        $rankGroupId = (int) ($run->rank_group_id ?? 0);
        if ($rankGroupId <= 0) {
            $this->markRunFailed($run, 'Missing rank group on run.');

            return;
        }

        $keywordIds = array_column($this->groupItems, 'keyword_id');
        $itemIdByKeyword = collect($this->groupItems)
            ->mapWithKeys(static fn (array $row): array => [(int) $row['keyword_id'] => (int) $row['item_id']])
            ->all();

        $keywords = Keyword::query()->whereIn('id', $keywordIds)->get();

        $processed = 0;
        $failed = 0;

        foreach ($keywords as $keyword) {
            $itemId = $itemIdByKeyword[(int) $keyword->id] ?? null;
            if ($itemId === null) {
                $failed++;

                continue;
            }

            try {
                if ($this->metricType === 'allintitle') {
                    $ok = $this->processAllintitle(
                        $run,
                        $registry,
                        $serpConnections,
                        $metricWriter,
                        $rankGroupId,
                        (int) $itemId,
                        $keyword,
                    );
                } elseif ($this->metricType === 'search_volume') {
                    $ok = $this->processSearchVolume(
                        $metricWriter,
                        $volumeService,
                        $rankGroupId,
                        (int) $itemId,
                        $keyword,
                    );
                } else {
                    $failed++;

                    continue;
                }

                if ($ok) {
                    $processed++;
                } else {
                    $failed++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        $run->refresh();
        $run->processed_keywords = (int) $run->processed_keywords + $processed;
        $run->failed_keywords = (int) $run->failed_keywords + $failed;
        $this->finalizeRunIfDone($run, $serpConnections);
        $run->save();
    }

    public function failed(?Throwable $exception): void
    {
        $run = KeywordRankCheckRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        $this->markRunFailed($run, $exception?->getMessage() ?? 'Metric batch failed.');
    }

    private function processAllintitle(
        KeywordRankCheckRun $run,
        SerpRankProviderRegistry $registry,
        SeoSerpProviderConnectionService $serpConnections,
        KeywordGroupMetricSnapshotWriter $metricWriter,
        int $rankGroupId,
        int $itemId,
        Keyword $keyword,
    ): bool {
        $connection = $serpConnections->resolveForUser($this->userId, $this->provider);
        if ($connection === null || ! $connection->isConfigured()) {
            return false;
        }

        $provider = $registry->get($this->provider);
        $request = new SerpRankRequest(
            keyword: (string) $keyword->phrase,
            country: $this->country,
            language: $this->language,
            location: $this->location,
            device: $this->device,
            depth: 1,
            trackedDomain: null,
        );

        $result = $provider->searchAllintitle($connection, $request);
        $metricWriter->persistAllintitle(
            rankGroupId: $rankGroupId,
            rankGroupItemId: $itemId,
            keywordId: (int) $keyword->id,
            connection: $connection,
            result: $result,
            runId: (int) $run->id,
        );

        return $result->status !== 'failed';
    }

    private function processSearchVolume(
        KeywordGroupMetricSnapshotWriter $metricWriter,
        KeywordSearchVolumeService $volumeService,
        int $rankGroupId,
        int $itemId,
        Keyword $keyword,
    ): bool {
        $resolved = $volumeService->resolveVolume(
            userId: $this->userId,
            keyword: (string) $keyword->phrase,
            location: $this->location,
            language: $this->language,
        );

        $metricWriter->persistSearchVolume(
            rankGroupId: $rankGroupId,
            rankGroupItemId: $itemId,
            keywordId: (int) $keyword->id,
            volume: $resolved['volume'],
            status: $resolved['status'],
            source: $resolved['source'],
            errorMessage: $resolved['error'],
            runId: $this->runId,
        );

        return $resolved['status'] !== KeywordMetricStatus::Failed->value;
    }

    private function finalizeRunIfDone(KeywordRankCheckRun $run, SeoSerpProviderConnectionService $serpConnections): void
    {
        if (($run->processed_keywords + $run->failed_keywords) < (int) $run->total_keywords) {
            return;
        }

        $run->status = 'completed';
        $run->completed_at = now();

        if ($this->metricType === 'allintitle') {
            $connection = $serpConnections->resolveForUser($this->userId, $this->provider);
            if ($connection !== null) {
                $serpConnections->markRankCheckCompleted($connection);
            }
        }
    }

    private function markRunFailed(KeywordRankCheckRun $run, string $message): void
    {
        $run->status = 'failed';
        $run->last_error = mb_substr($message, 0, 240);
        $run->completed_at = now();
        $run->save();
    }
}
