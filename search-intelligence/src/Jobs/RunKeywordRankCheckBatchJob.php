<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Jobs;

use Omnichannel\Addons\Content\DataTransfer\SerpRankRequest;
use Omnichannel\Addons\Content\DataTransfer\SerpRankResult;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankCheckRun;
use Omnichannel\Addons\SearchIntelligence\Providers\Serp\SerpRankProviderRegistry;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordRankSnapshotWriter;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\SearchIntelligence\Services\SeoSerpProviderConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RunKeywordRankCheckBatchJob implements ShouldQueue
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
        public array $groupItems,
        public string $connectionHash,
        public ?string $country = null,
        public ?string $location = null,
        public ?string $language = null,
        public ?string $device = null,
        public ?string $trackedDomain = null,
    ) {}

    public function handle(
        SeoSerpProviderConnectionService $serpConnections,
        SerpRankProviderRegistry $registry,
        KeywordRankSnapshotWriter $snapshotWriter,
        SeoDatabaseConnectionService $databaseConnection,
    ): void {
        $databaseConnection->bootstrapByHash($this->connectionHash);

        $run = KeywordRankCheckRun::query()->find($this->runId);
        if ($run === null || in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }

        $connection = $serpConnections->resolveForUser($this->userId, $this->provider);
        if ($connection === null || ! $connection->isConfigured()) {
            $this->markRunFailed($run, __('seo-content-ai::filament.api_connections.serp_not_configured'));

            return;
        }

        $provider = $registry->get($this->provider);
        $keywordIds = array_column($this->groupItems, 'keyword_id');
        $itemIdByKeyword = collect($this->groupItems)
            ->mapWithKeys(static fn (array $row): array => [(int) $row['keyword_id'] => (int) $row['item_id']])
            ->all();

        $keywords = Keyword::query()
            ->whereIn('id', $keywordIds)
            ->get();

        $processed = 0;
        $failed = 0;

        foreach ($keywords as $keyword) {
            try {
                $request = new SerpRankRequest(
                    keyword: (string) $keyword->phrase,
                    country: $this->country,
                    language: $this->language,
                    location: $this->location,
                    device: $this->device,
                    depth: (int) ($connection->result_depth ?: 100),
                    trackedDomain: $this->trackedDomain,
                );

                $result = $provider->search($connection, $request);
                $snapshotWriter->persist(
                    siteId: null,
                    keywordId: (int) $keyword->id,
                    connection: $connection,
                    result: $result,
                    runId: $this->runId,
                    rankGroupId: (int) ($run->rank_group_id ?? 0) ?: null,
                    rankGroupItemId: $itemIdByKeyword[(int) $keyword->id] ?? null,
                );

                if ($this->isFailureStatus($result->status)) {
                    $failed++;
                } else {
                    $processed++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        $run->refresh();
        $run->processed_keywords = (int) $run->processed_keywords + $processed;
        $run->failed_keywords = (int) $run->failed_keywords + $failed;

        if (($run->processed_keywords + $run->failed_keywords) >= (int) $run->total_keywords) {
            $run->status = 'completed';
            $run->completed_at = now();
            $serpConnections->markRankCheckCompleted($connection);
        }

        $run->save();
    }

    public function failed(?Throwable $exception): void
    {
        $run = KeywordRankCheckRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        $this->markRunFailed($run, $exception?->getMessage() ?? 'Rank check batch failed.');
    }

    private function markRunFailed(KeywordRankCheckRun $run, string $message): void
    {
        $run->status = 'failed';
        $run->last_error = mb_substr($message, 0, 240);
        $run->completed_at = now();
        $run->save();
    }

    private function isFailureStatus(string $status): bool
    {
        return ! in_array($status, [
            SerpRankResult::STATUS_SUCCESS_FOUND,
            SerpRankResult::STATUS_SUCCESS_NOT_FOUND,
        ], true);
    }
}
