<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchFoundation\Support\KeywordOrphanCleanup;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordMultiSiteOwnership;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\VocabularySuggestStagingQuery;
use RuntimeException;

/**
 * Physically remove a Vocabulary Suggest staging candidate from a site after Draft consume.
 * Idempotent. Hard-deletes global keyword only when orphaned after site detach.
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

            $shared = $this->isSharedWithOtherSites($keywordId, $siteId);
            if ($shared) {
                return;
            }

            KeywordOrphanCleanup::deleteUnusedByIds([$keywordId]);
            $deleted = ! Keyword::query()->whereKey($keywordId)->exists();
        });

        return [
            'keyword_id' => $keywordId,
            'phrase' => $phrase,
            'detached' => true,
            'deleted' => $deleted,
            'already_absent' => false,
            'shared_with_other_sites' => $shared,
        ];
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
