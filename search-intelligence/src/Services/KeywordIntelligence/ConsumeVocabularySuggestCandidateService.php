<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchFoundation\Support\KeywordOrphanCleanup;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordMultiSiteOwnership;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\VocabularySuggestStagingQuery;
use RuntimeException;

/**
 * Physically remove a Vocabulary Suggest staging candidate from a site after Draft consume.
 * Idempotent. Never mutates global classification while the keyword is still shared.
 * Hard-deletes global keyword only when orphaned after site detach.
 *
 * @phpstan-type ConsumeResult array{
 *   keyword_id: int,
 *   phrase: string,
 *   detached: bool,
 *   deleted: bool,
 *   already_absent: bool,
 *   shared_with_other_sites: bool,
 *   classification_cleared: bool
 * }
 */
final class ConsumeVocabularySuggestCandidateService
{
    public function __construct(
        private readonly KeywordPersistenceService $persistence,
        private readonly PruneAutoSingletonClustersService $singletonPruner,
    ) {}

    /**
     * @return ConsumeResult
     */
    public function consume(int $keywordId, int $siteId): array
    {
        if ($keywordId <= 0) {
            throw new RuntimeException('invalid_keyword_id');
        }
        if ($siteId <= 0) {
            throw new RuntimeException('invalid_site_id');
        }

        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            return [
                'keyword_id' => $keywordId,
                'phrase' => '',
                'detached' => false,
                'deleted' => false,
                'already_absent' => true,
                'shared_with_other_sites' => false,
                'classification_cleared' => false,
            ];
        }

        $phrase = Keyword::decodePhrase((string) ($keyword->phrase ?? ''));

        if ((string) ($keyword->type ?? '') !== Keyword::TYPE_SUGGEST) {
            throw new RuntimeException('not_vocabulary_suggest');
        }

        $source = (string) ($keyword->source ?? '');
        if ($source !== KeywordSourceNormalizer::AI_GENERATED) {
            throw new RuntimeException('not_vocabulary_suggest');
        }

        $inStaging = VocabularySuggestStagingQuery::forSite($siteId)
            ->whereKey($keywordId)
            ->exists();

        if (! $inStaging) {
            $shared = $this->isSharedWithOtherSites($keywordId, $siteId);
            $deleted = false;
            if (! $shared) {
                $deleted = $this->deleteIfOrphan($keywordId);
            }

            return [
                'keyword_id' => $keywordId,
                'phrase' => $phrase,
                'detached' => false,
                'deleted' => $deleted,
                'already_absent' => true,
                'shared_with_other_sites' => $shared,
                'classification_cleared' => false,
            ];
        }

        $shared = false;
        $deleted = false;
        $classificationCleared = false;

        DB::connection('omi_seo_ai')->transaction(function () use (
            $keyword,
            $keywordId,
            $siteId,
            &$shared,
            &$deleted,
            &$classificationCleared,
        ): void {
            // Ownership check BEFORE any global mutation. Detach site-local data first.
            $this->persistence->detachKeywordFromSite($keyword, $siteId);

            $shared = $this->isSharedWithOtherSites($keywordId, $siteId);
            if ($shared) {
                // Keep global seo_keyword_classifications.cluster_key for other sites.
                // Site-local MCP/cluster dirty flags still refresh for the consuming site.
                return;
            }

            $classificationCleared = $this->clearClassificationIfOrphan($keywordId, $siteId);
            KeywordOrphanCleanup::deleteUnusedByIds([$keywordId]);
            $deleted = ! Keyword::query()->whereKey($keywordId)->exists();
        });

        TopicClusterDirtyState::mark($siteId, 'vocabulary_suggest_consumed');
        SiteMcpTopicalProfileStaleState::mark($siteId, 'vocabulary_suggest_consumed');

        return [
            'keyword_id' => $keywordId,
            'phrase' => $phrase,
            'detached' => true,
            'deleted' => $deleted,
            'already_absent' => false,
            'shared_with_other_sites' => $shared,
            'classification_cleared' => $classificationCleared,
        ];
    }

    /**
     * Clear global classification only when keyword is not shared with another site.
     */
    private function clearClassificationIfOrphan(int $keywordId, int $siteId): bool
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')) {
            return false;
        }

        $row = DB::connection('omi_seo_ai')->table('seo_keyword_classifications')
            ->where('keyword_id', $keywordId)
            ->first(['keyword_id', 'cluster_key']);

        if ($row === null) {
            return false;
        }

        $previousCluster = trim((string) ($row->cluster_key ?? ''));
        DB::connection('omi_seo_ai')->table('seo_keyword_classifications')
            ->where('keyword_id', $keywordId)
            ->update(['cluster_key' => null]);

        if ($previousCluster !== '' && $siteId > 0) {
            $touched = [$previousCluster => true];
            $this->singletonPruner->prune($siteId, $touched);
        }

        return true;
    }

    public function isSharedWithOtherSites(int $keywordId, int $excludeSiteId): bool
    {
        return KeywordMultiSiteOwnership::isSharedWithOtherSites($keywordId, $excludeSiteId);
    }

    private function deleteIfOrphan(int $keywordId): bool
    {
        KeywordOrphanCleanup::deleteUnusedByIds([$keywordId]);

        return ! Keyword::query()->whereKey($keywordId)->exists();
    }
}
