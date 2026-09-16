<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchFoundation\Support\KeywordOrphanCleanup;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\VocabularySuggestStagingQuery;
use RuntimeException;

/**
 * Physically remove a Vocabulary Suggest staging candidate from a site after Draft consume.
 * Idempotent. Does not soft-hide (that is manual dismiss). Hard-deletes global keyword only when orphaned.
 *
 * @phpstan-type ConsumeResult array{
 *   keyword_id: int,
 *   phrase: string,
 *   detached: bool,
 *   deleted: bool,
 *   already_absent: bool,
 *   shared_with_other_sites: bool
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
            // Already detached from this site — still try orphan cleanup (idempotent).
            $deleted = $this->deleteIfOrphan($keywordId);

            return [
                'keyword_id' => $keywordId,
                'phrase' => $phrase,
                'detached' => false,
                'deleted' => $deleted,
                'already_absent' => true,
                'shared_with_other_sites' => $this->hasOtherSiteMeta($keywordId, $siteId),
            ];
        }

        $shared = false;
        $deleted = false;

        DB::connection('omi_seo_ai')->transaction(function () use (
            $keyword,
            $keywordId,
            $siteId,
            &$shared,
            &$deleted,
        ): void {
            $this->persistence->detachKeywordFromSite($keyword, $siteId);
            $this->detachClassificationIfPresent($keywordId, $siteId);

            $shared = $this->hasOtherSiteMeta($keywordId, $siteId);
            if (! $shared) {
                KeywordOrphanCleanup::deleteUnusedByIds([$keywordId]);
                $deleted = ! Keyword::query()->whereKey($keywordId)->exists();
            }
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
        ];
    }

    private function detachClassificationIfPresent(int $keywordId, int $siteId): void
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_classifications')) {
            return;
        }

        $row = DB::connection('omi_seo_ai')->table('seo_keyword_classifications')
            ->where('keyword_id', $keywordId)
            ->first(['keyword_id', 'cluster_key']);

        if ($row === null) {
            return;
        }

        $previousCluster = trim((string) ($row->cluster_key ?? ''));
        DB::connection('omi_seo_ai')->table('seo_keyword_classifications')
            ->where('keyword_id', $keywordId)
            ->update(['cluster_key' => null]);

        if ($previousCluster !== '' && $siteId > 0) {
            $this->singletonPruner->prune($siteId, [$previousCluster => true]);
        }
    }

    private function hasOtherSiteMeta(int $keywordId, int $excludeSiteId): bool
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return false;
        }

        $metas = DB::connection('omi_seo_ai')->table('keyword_meta')
            ->where('keyword_id', $keywordId)
            ->where('meta_key', 'like', 'site.%')
            ->pluck('meta_key');

        foreach ($metas as $key) {
            if (preg_match('/^site\.(\d+)\./', (string) $key, $m) !== 1) {
                continue;
            }
            if ((int) $m[1] !== $excludeSiteId && (int) $m[1] > 0) {
                return true;
            }
        }

        // Link maps to articles on other sites also count as shared use.
        if (Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')
            && Schema::connection('omi_seo_ai')->hasTable('seo_articles')) {
            return DB::connection('omi_seo_ai')->table('seo_link_maps as lm')
                ->join('seo_articles as a', 'a.id', '=', 'lm.source_article_id')
                ->where('lm.keyword_id', $keywordId)
                ->where('a.site_id', '!=', $excludeSiteId)
                ->exists();
        }

        return false;
    }

    private function deleteIfOrphan(int $keywordId): bool
    {
        KeywordOrphanCleanup::deleteUnusedByIds([$keywordId]);

        return ! Keyword::query()->whereKey($keywordId)->exists();
    }
}
