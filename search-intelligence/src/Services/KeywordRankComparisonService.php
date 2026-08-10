<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchIntelligence\Jobs\RunSingleKeywordRankCheckJob;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordRankCheckRun;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class KeywordRankComparisonService
{
    public const MAX_KEYWORDS = 5;

    public function __construct(
        private readonly SeoSerpProviderConnectionService $serpConnections,
    ) {}

    /**
     * @param  list<string>  $providers
     * @param  list<int>|null  $keywordIds
     * @return array{queued: bool, batch_id: string, job_count: int}
     */
    public function dispatchComparison(
        int $userId,
        array $providers,
        ?array $keywordIds = null,
        ?string $keywordPhrase = null,
        ?string $country = null,
        ?string $location = null,
        ?string $language = null,
        ?string $device = null,
        ?string $trackedDomain = null,
    ): array {
        $connectionHash = SeoConnectionContext::hash();
        if ($connectionHash === null) {
            throw new \RuntimeException(__('seo-content-ai::filament.rank_group.missing_connection_context'));
        }

        $providers = array_values(array_filter(
            $providers,
            static fn (string $provider): bool => SerpProviderKeys::isValid($provider),
        ));

        if ($providers === []) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.comparison_no_providers'));
        }

        foreach ($providers as $provider) {
            $connection = $this->serpConnections->resolveForUser($userId, $provider);
            if ($connection === null || ! $connection->isConfigured()) {
                throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.comparison_provider_not_configured', [
                    'provider' => SerpProviderKeys::label($provider),
                ]));
            }
        }

        $resolvedKeywordIds = $this->resolveKeywordIds($keywordIds, $keywordPhrase);
        if ($resolvedKeywordIds === []) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.comparison_no_keywords'));
        }

        if (count($resolvedKeywordIds) > self::MAX_KEYWORDS) {
            throw new \RuntimeException(__('seo-content-ai::filament.performance_hub.comparison_too_many_keywords', [
                'max' => self::MAX_KEYWORDS,
            ]));
        }

        $batchId = (string) Str::uuid();
        $jobCount = 0;

        DB::connection('omi_seo_ai')->transaction(function () use (
            $userId,
            $providers,
            $resolvedKeywordIds,
            $batchId,
            $country,
            $location,
            $language,
            $device,
            $trackedDomain,
            $connectionHash,
            &$jobCount,
        ): void {
            foreach ($providers as $provider) {
                $connection = $this->serpConnections->resolveForUser($userId, $provider);
                if ($connection === null) {
                    continue;
                }

                $run = KeywordRankCheckRun::query()->create([
                    'site_id' => null,
                    'connection_hash' => $connectionHash,
                    'user_id' => $userId,
                    'status' => 'running',
                    'run_type' => 'comparison',
                    'comparison_batch_id' => $batchId,
                    'total_keywords' => count($resolvedKeywordIds),
                    'processed_keywords' => 0,
                    'failed_keywords' => 0,
                    'provider' => $provider,
                    'connection_id' => (int) $connection->id,
                    'country' => $country,
                    'location' => $location,
                    'language' => $language,
                    'device' => $device,
                    'started_at' => now(),
                    'metadata' => ['providers' => $providers],
                ]);

                foreach ($resolvedKeywordIds as $keywordId) {
                    RunSingleKeywordRankCheckJob::dispatch(
                        runId: (int) $run->id,
                        userId: $userId,
                        provider: $provider,
                        keywordId: (int) $keywordId,
                        connectionHash: $connectionHash,
                        country: $country,
                        location: $location,
                        language: $language,
                        device: $device,
                        trackedDomain: $trackedDomain,
                        comparisonBatchId: $batchId,
                    )->onQueue('seo');

                    $jobCount++;
                }
            }
        });

        return [
            'queued' => $jobCount > 0,
            'batch_id' => $batchId,
            'job_count' => $jobCount,
        ];
    }

    /**
     * @param  list<int>|null  $keywordIds
     * @return list<int>
     */
    private function resolveKeywordIds(?array $keywordIds, ?string $keywordPhrase): array
    {
        if (is_array($keywordIds) && $keywordIds !== []) {
            return Keyword::query()
                ->whereIn('id', $keywordIds)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        $phrase = trim((string) $keywordPhrase);
        if ($phrase === '') {
            return [];
        }

        $keyword = Keyword::query()
            ->where('phrase', $phrase)
            ->first();

        return $keyword !== null ? [(int) $keyword->id] : [];
    }
}
