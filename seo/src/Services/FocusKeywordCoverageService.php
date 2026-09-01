<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;

/**
 * Article-level Focus Keyword coverage for Domain General / Posts filter.
 *
 * Does NOT use Dictionary tab counts (unique keyword rows with mainArticles).
 */
final class FocusKeywordCoverageService
{
    public function __construct(
        private readonly FocusKeywordCoverageQuery $query = new FocusKeywordCoverageQuery(),
    ) {}

    /**
     * @return array{
     *   eligible_article_count: int,
     *   articles_with_focus_keyword: int,
     *   missing_focus_keyword_articles: int,
     *   coverage_pct: float|null,
     *   missing_article_ids: list<int>,
     *   unique_effective_focus_phrases: int,
     *   focus_article_relations: int,
     *   source_breakdown: array{
     *     manual: int,
     *     provider: int,
     *     workspace: int,
     *     other: int,
     *     semantics: string
     *   },
     *   ok: bool,
     *   filter_url: string|null
     * }
     */
    public function forSite(int $siteId, ?string $missingFilterUrl = null): array
    {
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return $this->empty($missingFilterUrl);
        }

        $eligibleIds = $this->query->eligibleQuery($siteId)
            ->orderBy('articles.id')
            ->pluck('articles.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $eligibleCount = count($eligibleIds);
        if ($eligibleCount === 0) {
            return $this->empty($missingFilterUrl);
        }

        $coveredIds = $this->query->coveredEligibleQuery($siteId)
            ->orderBy('articles.id')
            ->pluck('articles.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $coveredSet = array_fill_keys($coveredIds, true);
        $missingIds = [];
        foreach ($eligibleIds as $id) {
            if (! isset($coveredSet[$id])) {
                $missingIds[] = $id;
            }
        }

        $coveredCount = count($coveredIds);
        $missingCount = count($missingIds);
        $phraseStats = $this->phraseAndSourceStats($coveredIds);

        return [
            'eligible_article_count' => $eligibleCount,
            'articles_with_focus_keyword' => $coveredCount,
            'missing_focus_keyword_articles' => $missingCount,
            'coverage_pct' => $eligibleCount > 0
                ? round(($coveredCount / $eligibleCount) * 100, 1)
                : null,
            'missing_article_ids' => $missingIds,
            'unique_effective_focus_phrases' => $phraseStats['unique_phrases'],
            'focus_article_relations' => $phraseStats['relations'],
            'source_breakdown' => $phraseStats['source_breakdown'],
            'ok' => $missingCount === 0,
            'filter_url' => $missingFilterUrl,
        ];
    }

    /**
     * @return list<int>
     */
    public function missingArticleIds(int $siteId): array
    {
        return $this->forSite($siteId)['missing_article_ids'];
    }

    public function query(): FocusKeywordCoverageQuery
    {
        return $this->query;
    }

    /**
     * @param  list<int>  $coveredIds
     * @return array{
     *   unique_phrases: int,
     *   relations: int,
     *   source_breakdown: array{manual: int, provider: int, workspace: int, other: int, semantics: string}
     * }
     */
    private function phraseAndSourceStats(array $coveredIds): array
    {
        $emptyBreakdown = [
            'manual' => 0,
            'provider' => 0,
            'workspace' => 0,
            'other' => 0,
            'semantics' => 'effective ownership priority: manual > provider > workspace (source_locked counts as manual)',
        ];

        if ($coveredIds === []) {
            return [
                'unique_phrases' => 0,
                'relations' => 0,
                'source_breakdown' => $emptyBreakdown,
            ];
        }

        $metaPhrases = DB::connection('omi_seo_ai')
            ->table('article_meta')
            ->where('meta_key', 'seo_focus_keyword')
            ->whereIn('article_id', $coveredIds)
            ->whereNotNull('meta_value')
            ->where('meta_value', '!=', '')
            ->whereRaw("TRIM(meta_value) <> ''")
            ->get(['article_id', 'meta_value']);

        $metaByArticle = [];
        foreach ($metaPhrases as $row) {
            $phrase = Keyword::normalizeFocusPhrase((string) $row->meta_value);
            if ($phrase !== '') {
                $metaByArticle[(int) $row->article_id] = $phrase;
            }
        }

        $relations = 0;
        $keywordByArticle = [];
        if (Schema::connection('omi_seo_ai')->hasTable('keyword_meta')
            && Schema::connection('omi_seo_ai')->hasTable('keywords')) {
            $keywordRows = DB::connection('omi_seo_ai')
                ->table('keyword_meta as km')
                ->join('keywords as k', 'k.id', '=', 'km.keyword_id')
                ->where('km.meta_key', KeywordMetaKey::MainArticleId->value)
                ->whereIn('km.meta_value', array_map(static fn (int $id): string => (string) $id, $coveredIds))
                ->whereNotNull('k.phrase')
                ->where('k.phrase', '!=', '')
                ->whereRaw("TRIM(k.phrase) <> ''")
                ->get(['km.meta_value as article_id', 'k.phrase', 'k.source', 'k.source_locked']);

            $relations = $keywordRows->count();

            foreach ($keywordRows as $row) {
                $articleId = (int) $row->article_id;
                $phrase = Keyword::normalizeFocusPhrase((string) $row->phrase);
                if ($phrase === '') {
                    continue;
                }
                $source = $this->normalizeSource(
                    (string) ($row->source ?? ''),
                    (bool) ($row->source_locked ?? false),
                );
                $existing = $keywordByArticle[$articleId] ?? null;
                if ($existing === null || $this->sourceRank($source) < $this->sourceRank($existing['source'])) {
                    $keywordByArticle[$articleId] = [
                        'phrase' => $phrase,
                        'source' => $source,
                    ];
                }
            }
        }

        $unique = [];
        $breakdown = $emptyBreakdown;
        foreach ($coveredIds as $articleId) {
            $phrase = $metaByArticle[$articleId] ?? ($keywordByArticle[$articleId]['phrase'] ?? null);
            if (is_string($phrase) && $phrase !== '') {
                $unique[mb_strtolower($phrase)] = true;
            }

            $source = $keywordByArticle[$articleId]['source'] ?? null;
            if ($source === null && isset($metaByArticle[$articleId])) {
                $source = SiteSyncSchema::SOURCE_MANUAL;
            }
            $bucket = match ($source) {
                SiteSyncSchema::SOURCE_MANUAL => 'manual',
                SiteSyncSchema::SOURCE_PROVIDER => 'provider',
                SiteSyncSchema::SOURCE_WORKSPACE,
                SiteSyncSchema::SOURCE_LEGACY_WORKSPACE => 'workspace',
                default => 'other',
            };
            $breakdown[$bucket]++;
        }

        return [
            'unique_phrases' => count($unique),
            'relations' => $relations,
            'source_breakdown' => $breakdown,
        ];
    }

    private function normalizeSource(string $source, bool $locked): string
    {
        if ($locked || $source === SiteSyncSchema::SOURCE_MANUAL) {
            return SiteSyncSchema::SOURCE_MANUAL;
        }

        return match ($source) {
            SiteSyncSchema::SOURCE_PROVIDER => SiteSyncSchema::SOURCE_PROVIDER,
            SiteSyncSchema::SOURCE_WORKSPACE,
            SiteSyncSchema::SOURCE_LEGACY_WORKSPACE => SiteSyncSchema::SOURCE_WORKSPACE,
            default => $source !== '' ? $source : 'other',
        };
    }

    private function sourceRank(string $source): int
    {
        return match ($source) {
            SiteSyncSchema::SOURCE_MANUAL => 0,
            SiteSyncSchema::SOURCE_PROVIDER => 1,
            SiteSyncSchema::SOURCE_WORKSPACE,
            SiteSyncSchema::SOURCE_LEGACY_WORKSPACE => 2,
            default => 9,
        };
    }

    /**
     * @return array{
     *   eligible_article_count: int,
     *   articles_with_focus_keyword: int,
     *   missing_focus_keyword_articles: int,
     *   coverage_pct: float|null,
     *   missing_article_ids: list<int>,
     *   unique_effective_focus_phrases: int,
     *   focus_article_relations: int,
     *   source_breakdown: array{manual: int, provider: int, workspace: int, other: int, semantics: string},
     *   ok: bool,
     *   filter_url: string|null
     * }
     */
    private function empty(?string $missingFilterUrl): array
    {
        return [
            'eligible_article_count' => 0,
            'articles_with_focus_keyword' => 0,
            'missing_focus_keyword_articles' => 0,
            'coverage_pct' => null,
            'missing_article_ids' => [],
            'unique_effective_focus_phrases' => 0,
            'focus_article_relations' => 0,
            'source_breakdown' => [
                'manual' => 0,
                'provider' => 0,
                'workspace' => 0,
                'other' => 0,
                'semantics' => 'effective ownership priority: manual > provider > workspace (source_locked counts as manual)',
            ],
            'ok' => true,
            'filter_url' => $missingFilterUrl,
        ];
    }
}
