<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Comparison;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SiteSync\Models\SeoArticleScoreSource;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkCatalog;
use Omnichannel\Addons\SiteSync\Models\SeoSiteManualLink;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncComparisonDiff;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncComparisonRun;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Cutover\SiteSyncCutoverStateService;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\KeywordNormalizationService;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Readonly dual-read comparison — never mutates business data.
 */
final class SiteSyncComparisonService
{
    public const SCOPE_SUMMARY = 'summary';

    public const SCOPE_FULL = 'full';

    public function __construct(
        private readonly SiteSyncDifferenceClassifier $classifier,
        private readonly KeywordNormalizationService $normalizer,
        private readonly SiteDomainPromptContextService $promptContext,
        private readonly SiteSyncCutoverStateService $cutover,
    ) {}

    /**
     * @return array{success: bool, message: string, run_id?: int, public_ref?: string, summary?: array<string, mixed>}
     */
    public function compare(Site $site, string $scope = self::SCOPE_SUMMARY, int $limit = 500): array
    {
        $siteId = (int) $site->id;
        $run = SeoSiteSyncComparisonRun::query()->create([
            'site_id' => $siteId,
            'public_ref' => 'ssc_'.Str::lower(Str::random(12)),
            'status' => 'running',
            'scope' => $scope,
            'started_at' => now(),
            'summary' => [],
        ]);

        $diffs = [];
        $diffs = array_merge($diffs, $this->compareArticles($site, $limit));
        $diffs = array_merge($diffs, $this->compareLinks($site, $limit));
        $diffs = array_merge($diffs, $this->compareKeywords($site, $limit));
        $diffs = array_merge($diffs, $this->compareScores($site, $limit));
        $diffs = array_merge($diffs, $this->compareProfile($site));

        $blocking = 0;
        $needsReview = 0;
        $expected = 0;
        foreach ($diffs as $diff) {
            if ($diff['classification'] === SiteSyncDifferenceClassifier::BLOCKING) {
                $blocking++;
            } elseif ($diff['classification'] === SiteSyncDifferenceClassifier::NEEDS_REVIEW) {
                $needsReview++;
            } elseif (in_array($diff['classification'], [
                SiteSyncDifferenceClassifier::EXPECTED,
                SiteSyncDifferenceClassifier::HARMLESS,
                SiteSyncDifferenceClassifier::NORMALIZATION,
                SiteSyncDifferenceClassifier::PROVIDER_FORMULA,
                SiteSyncDifferenceClassifier::OWNERSHIP,
            ], true)) {
                $expected++;
            }
        }

        $persistLimit = min(count($diffs), $limit);
        for ($i = 0; $i < $persistLimit; $i++) {
            $d = $diffs[$i];
            SeoSiteSyncComparisonDiff::query()->create([
                'run_id' => (int) $run->id,
                'site_id' => $siteId,
                'group_key' => $d['group_key'],
                'entity_key' => $d['entity_key'],
                'classification' => $d['classification'],
                'reason_code' => $d['reason_code'],
                'message' => $d['message'],
                'legacy_value' => $d['legacy_value'],
                'v2_value' => $d['v2_value'],
            ]);
        }

        $summary = [
            'mode' => $this->cutover->modeFor($site),
            'total_diffs' => count($diffs),
            'persisted' => $persistLimit,
            'blocking' => $blocking,
            'needs_review' => $needsReview,
            'expected' => $expected,
            'scope' => $scope,
            'mutates' => false,
        ];

        $run->forceFill([
            'status' => 'completed',
            'blocking_count' => $blocking,
            'needs_review_count' => $needsReview,
            'expected_count' => $expected,
            'summary' => $summary,
            'finished_at' => now(),
        ])->save();

        return [
            'success' => true,
            'message' => 'Comparison completed (readonly)',
            'run_id' => (int) $run->id,
            'public_ref' => (string) $run->public_ref,
            'summary' => $summary,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compareArticles(Site $site, int $limit): array
    {
        $out = [];
        $articles = SeoArticle::query()
            ->leftJoin('wordpress_article_links as wal_compare', 'wal_compare.article_id', '=', 'articles.id')
            ->where('articles.site_id', (int) $site->id)
            ->whereNotNull('wal_compare.wp_post_id')
            ->orderBy('articles.id')
            ->limit($limit)
            ->get([
                'articles.id as id',
                'wal_compare.wp_post_id as wp_post_id',
                'articles.title as title',
                'articles.status as status',
                'articles.slug as slug',
            ]);

        foreach ($articles as $article) {
            if ((int) ($article->wordpressLink?->wp_post_id ?? 0) <= 0) {
                $c = $this->classifier->classify('article', 'legacy_data_invalid');
                $out[] = $this->diff('article', 'article:'.$article->id, $c, 'Article missing wordpress_id', [
                    'id' => (int) $article->id,
                ], null);
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compareLinks(Site $site, int $limit): array
    {
        $out = [];
        $ctx = $this->promptContext->getForSite($site);
        $legacyLinks = is_array($ctx['links'] ?? null) ? $ctx['links'] : [];
        $manualHashes = SeoSiteManualLink::query()
            ->where('site_id', (int) $site->id)
            ->pluck('url_hash')
            ->all();

        foreach (array_slice($legacyLinks, 0, $limit) as $row) {
            $url = trim((string) ($row['link'] ?? ''));
            if ($url === '') {
                continue;
            }
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                $c = $this->classifier->classify('link', 'invalid_legacy_url');
                $out[] = $this->diff('link', $url, $c, 'Invalid legacy URL', ['url' => $url], null);
                continue;
            }
            $hash = hash('sha256', mb_strtolower($url));
            if (! in_array($hash, $manualHashes, true)) {
                $c = $this->classifier->classify('link', 'missing_in_v2', ['critical' => false]);
                $out[] = $this->diff('link', $hash, $c, 'Legacy domain link not yet in manual V2 table', [
                    'url' => $url,
                ], null);
            } else {
                $c = $this->classifier->classify('link', 'manual_link_separated');
                $out[] = $this->diff('link', $hash, $c, 'Manual link preserved separately from WP catalog', [
                    'url' => $url,
                ], ['source' => SiteSyncSchema::SOURCE_MANUAL]);
            }
        }

        $wpCatalog = SeoSiteLinkCatalog::query()
            ->forSite((int) $site->id)
            ->where('source', SiteSyncSchema::SOURCE_WORDPRESS)
            ->count();
        if ($wpCatalog === 0 && $legacyLinks !== []) {
            $c = $this->classifier->classify('link', 'missing_in_v2', ['critical' => false]);
            $out[] = $this->diff('link', 'catalog', $c, 'WP catalog empty while legacy links exist — bootstrap/reconcile needed', [
                'legacy_count' => count($legacyLinks),
            ], ['wp_catalog' => 0]);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compareKeywords(Site $site, int $limit): array
    {
        $out = [];
        if (! Schema::connection('omi_seo_ai')->hasColumn('keywords', 'source')) {
            return $out;
        }

        $rows = Keyword::query()
            ->when(
                Schema::connection('omi_seo_ai')->hasColumn('keywords', 'user_id') && $site->user_id,
                static fn ($q) => $q->where('user_id', (int) $site->user_id),
            )
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'phrase', 'source', 'source_locked']);

        $seen = [];
        foreach ($rows as $kw) {
            $norm = mb_strtolower($this->normalizer->normalize((string) $kw->phrase));
            if ($norm !== '' && isset($seen[$norm])) {
                $c = $this->classifier->classify('keyword', 'keyword_case_dedupe');
                $out[] = $this->diff('keyword', $norm, $c, 'Duplicate keyword after normalization', [
                    'phrase' => (string) $kw->phrase,
                ], ['canonical' => $norm]);
            }
            $seen[$norm] = true;

            $source = (string) ($kw->source ?? '');
            if ($source === '' || $source === SiteSyncSchema::SOURCE_LEGACY_UNKNOWN) {
                $c = $this->classifier->classify('keyword', 'legacy_score_unknown_provider');
                $out[] = $this->diff('keyword', 'kw:'.$kw->id, $c, 'Keyword source unknown/legacy', [
                    'id' => (int) $kw->id,
                    'source' => $source,
                ], null);
            }
            if ((bool) ($kw->source_locked ?? false) || $source === SiteSyncSchema::SOURCE_MANUAL) {
                $c = $this->classifier->classify('keyword', 'manual_override_preserved');
                $out[] = $this->diff('keyword', 'kw:'.$kw->id, $c, 'Manual keyword locked', [
                    'id' => (int) $kw->id,
                ], ['source' => 'manual']);
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compareScores(Site $site, int $limit): array
    {
        $out = [];
        $sources = SeoArticleScoreSource::query()
            ->where('site_id', (int) $site->id)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'article_id', 'source', 'score']);

        $byArticle = [];
        foreach ($sources as $row) {
            $aid = (int) $row->article_id;
            $byArticle[$aid][] = (string) $row->source;
            if ((string) $row->source === SiteSyncSchema::SOURCE_LEGACY_UNKNOWN) {
                $c = $this->classifier->classify('score', 'legacy_score_unknown_provider');
                $out[] = $this->diff('score', 'score:'.$row->id, $c, 'Legacy score without provider', [
                    'score' => $row->score,
                ], null);
            }
        }
        foreach ($byArticle as $aid => $srcs) {
            $unique = array_values(array_unique($srcs));
            if (count($unique) > 1) {
                $c = $this->classifier->classify('score', 'provider_score_incomparable');
                $out[] = $this->diff('score', 'article:'.$aid, $c, 'Multiple score providers — do not compare formulas', null, [
                    'providers' => $unique,
                ]);
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compareProfile(Site $site): array
    {
        $ctx = $this->promptContext->getForSite($site);
        $out = [];
        if (trim((string) ($ctx['tone'] ?? '')) !== '') {
            $c = $this->classifier->classify('profile', 'manual_override_preserved');
            $out[] = $this->diff('profile', 'tone', $c, 'Manual tone preserved', ['tone' => 'set'], null);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $legacy
     * @param  array<string, mixed>|null  $v2
     * @param  array{classification: string, reason_code: string}  $c
     * @return array<string, mixed>
     */
    private function diff(string $group, string $entity, array $c, string $message, ?array $legacy, ?array $v2): array
    {
        return [
            'group_key' => $group,
            'entity_key' => mb_substr($entity, 0, 180),
            'classification' => $c['classification'],
            'reason_code' => $c['reason_code'],
            'message' => $message,
            'legacy_value' => $legacy,
            'v2_value' => $v2,
        ];
    }
}
