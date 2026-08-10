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

final class RunSingleKeywordRankCheckJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public function __construct(
        public int $runId,
        public int $userId,
        public string $provider,
        public int $keywordId,
        public string $connectionHash,
        public ?int $rankGroupId = null,
        public ?int $rankGroupItemId = null,
        public ?string $country = null,
        public ?string $location = null,
        public ?string $language = null,
        public ?string $device = null,
        public ?string $trackedDomain = null,
        public ?string $comparisonBatchId = null,
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
            $this->incrementRunFailure($run);

            return;
        }

        $keyword = Keyword::query()->find($this->keywordId);
        if ($keyword === null) {
            $this->incrementRunFailure($run);

            return;
        }

        try {
            $provider = $registry->get($this->provider);
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
                rankGroupId: $this->rankGroupId,
                rankGroupItemId: $this->rankGroupItemId,
            );

            if ($this->isFailureStatus($result->status)) {
                $this->incrementRunFailure($run);
            } else {
                $this->incrementRunSuccess($run, $connection, $serpConnections);
            }
        } catch (Throwable) {
            $this->incrementRunFailure($run);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = KeywordRankCheckRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        $this->incrementRunFailure($run, $exception?->getMessage());
    }

    private function incrementRunSuccess(
        KeywordRankCheckRun $run,
        \Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection $connection,
        SeoSerpProviderConnectionService $serpConnections,
    ): void {
        $run->refresh();
        $run->processed_keywords = (int) $run->processed_keywords + 1;
        $this->finalizeRunIfDone($run, $connection, $serpConnections);
        $run->save();
    }

    private function incrementRunFailure(KeywordRankCheckRun $run, ?string $message = null): void
    {
        $run->refresh();
        $run->failed_keywords = (int) $run->failed_keywords + 1;
        if ($message !== null && $run->last_error === null) {
            $run->last_error = mb_substr($message, 0, 240);
        }
        $this->finalizeRunIfDone($run);
        $run->save();
    }

    private function finalizeRunIfDone(
        KeywordRankCheckRun $run,
        ?\Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection $connection = null,
        ?SeoSerpProviderConnectionService $serpConnections = null,
    ): void {
        if (($run->processed_keywords + $run->failed_keywords) < (int) $run->total_keywords) {
            return;
        }

        $run->status = 'completed';
        $run->completed_at = now();

        if ($connection !== null && $serpConnections !== null) {
            $serpConnections->markRankCheckCompleted($connection);
        }
    }

    private function isFailureStatus(string $status): bool
    {
        return ! in_array($status, [
            SerpRankResult::STATUS_SUCCESS_FOUND,
            SerpRankResult::STATUS_SUCCESS_NOT_FOUND,
        ], true);
    }
}
