<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\V3;

use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleLastSavedTimestampService;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\ArticleScoreSourceReconciler;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\CanonicalKeywordReconciler;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\ProviderKeywordReconciler;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteLinkCatalogReconciler;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteSyncKeywordCandidateEvaluator;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Omnichannel\Addons\WordPress\Services\WordpressArticleLinkWriter;
use Throwable;

/**
 * Lean V3 bulk importer — identity + catalog/keywords/scores/analysis links only.
 * MUST NOT write articles.body or wp_post_content* meta (content lifecycle immutable).
 */
final class SiteSyncV3BulkImporter
{
    /** Marker in seo_link_maps.context_before for V3 sync-sourced rows. */
    public const LINK_SYNC_MARKER = 'site_sync.v3';

    public function __construct(
        private readonly SiteLinkCatalogReconciler $links,
        private readonly ProviderKeywordReconciler $keywords,
        private readonly ArticleScoreSourceReconciler $scores,
        private readonly WordpressArticleLinkWriter $linkWriter,
        private readonly ArticleLastSavedTimestampService $lastSaved,
        private readonly CanonicalKeywordReconciler $canonicalKeywords = new CanonicalKeywordReconciler(),
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{upsert_count: int, delete_count: int, failed: int, links: int, keywords: int, scores: int}
     */
    public function importContentChunk(Site $site, SeoSiteSyncRun $run, array $items): array
    {
        return $this->importChunk($site, $run, $items, isTermResource: false);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{upsert_count: int, delete_count: int, failed: int, links: int, keywords: int, scores: int}
     */
    public function importTermsChunk(Site $site, SeoSiteSyncRun $run, array $items): array
    {
        return $this->importChunk($site, $run, $items, isTermResource: true);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{upsert_count: int, delete_count: int, failed: int, links: int, keywords: int, scores: int}
     */
    private function importChunk(Site $site, SeoSiteSyncRun $run, array $items, bool $isTermResource): array
    {
        $upserted = 0;
        $deleted = 0;
        $failed = 0;
        $linkRows = [];
        $keywordRows = [];
        $scoreRows = [];
        /** @var list<array{article: SeoArticle, links: list<array<string, mixed>>}> $analysisLinkJobs */
        $analysisLinkJobs = [];

        $sanitized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $sanitized[] = $this->stripForbiddenBodyKeys($item);
        }

        $generation = $this->syncGeneration($run);
        $byWpId = $this->preloadByWpPostIds($site, $sanitized);

        foreach ($sanitized as $item) {
            $op = strtolower(trim((string) ($item['op'] ?? $item['action'] ?? 'upsert')));
            $wpId = (int) ($item['wp_id'] ?? $item['wordpress_id'] ?? 0);
            if ($wpId <= 0) {
                $failed++;
                continue;
            }

            try {
                if ($op === 'delete') {
                    if ($this->deleteWpBackedOnly($site, $wpId, $byWpId)) {
                        $deleted++;
                    }
                    continue;
                }

                $article = $this->upsertIdentity($site, $item, $wpId, $isTermResource, $byWpId, $generation);
                if ($article === null) {
                    $failed++;
                    continue;
                }
                $upserted++;
                $byWpId[$wpId] = $article;

                $url = trim((string) ($item['url'] ?? $item['permalink'] ?? ''));
                if ($url !== '') {
                    $linkRows[] = [
                        'wordpress_id' => $wpId,
                        'url' => $url,
                        'canonical' => isset($item['canonical']) ? (string) $item['canonical'] : null,
                        'slug' => isset($item['slug']) ? (string) $item['slug'] : null,
                        'title' => (string) ($item['title'] ?? $item['post_title'] ?? $article->title ?? ''),
                        'status' => $this->normalizeStatus((string) ($item['status'] ?? 'publish')),
                        'type' => $isTermResource ? 'term' : (string) ($item['type'] ?? 'article'),
                        'content_hash' => isset($item['content_hash']) ? (string) $item['content_hash'] : null,
                        'updated_at' => $item['updated_at'] ?? $item['modified_at'] ?? null,
                        'meta' => is_array($item['meta'] ?? null) ? $item['meta'] : null,
                    ];
                }

                $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];

                $focusScalar = trim((string) (
                    $item['focus_keyword']
                    ?? ($seo['focus_keyword'] ?? '')
                    ?? ''
                ));
                if ($focusScalar !== '') {
                    $keywordRows[] = [
                        'phrase' => $focusScalar,
                        'source' => SiteSyncSchema::SOURCE_PROVIDER,
                        'wordpress_id' => $wpId,
                    ];
                }

                $focusKeywords = is_array($seo['focus_keywords'] ?? null) ? $seo['focus_keywords'] : [];
                foreach ($focusKeywords as $kw) {
                    if (is_string($kw)) {
                        $phrase = trim($kw);
                        if ($phrase !== '') {
                            $keywordRows[] = [
                                'phrase' => $phrase,
                                'source' => SiteSyncSchema::SOURCE_PROVIDER,
                                'provider' => (string) ($seo['provider'] ?? ''),
                                'wordpress_id' => $wpId,
                            ];
                        }
                        continue;
                    }
                    if (! is_array($kw)) {
                        continue;
                    }
                    $phrase = trim((string) ($kw['phrase'] ?? $kw['keyword'] ?? ''));
                    if ($phrase === '') {
                        continue;
                    }
                    $keywordRows[] = [
                        'phrase' => $phrase,
                        'source' => SiteSyncSchema::SOURCE_PROVIDER,
                        'provider' => (string) ($kw['provider'] ?? $seo['provider'] ?? ''),
                        'wordpress_id' => $wpId,
                    ];
                }

                $providerKeywords = is_array($item['provider_keywords'] ?? null) ? $item['provider_keywords'] : [];
                foreach ($providerKeywords as $kw) {
                    if (! is_array($kw)) {
                        continue;
                    }
                    $keywordRows[] = array_merge($kw, ['wordpress_id' => $wpId]);
                }

                $scores = is_array($item['scores'] ?? null) ? $item['scores'] : [];
                if ($scores === [] && isset($item['seo_score'])) {
                    $scores[] = [
                        'wordpress_id' => $wpId,
                        'source' => (string) ($item['seo_score_source'] ?? 'wordpress'),
                        'score' => $item['seo_score'],
                        'raw' => $item,
                    ];
                }
                foreach ($scores as $score) {
                    if (! is_array($score)) {
                        continue;
                    }
                    $scoreRows[] = array_merge(['wordpress_id' => $wpId], $score);
                }

                $providerScore = $seo['provider_score'] ?? null;
                if (is_array($providerScore)) {
                    $source = trim((string) ($providerScore['source'] ?? $seo['provider'] ?? ''));
                    if ($source !== '') {
                        $scoreRows[] = [
                            'wordpress_id' => $wpId,
                            'source' => $source,
                            'score' => $providerScore['score'] ?? null,
                            'raw' => $providerScore,
                        ];
                    }
                } elseif (is_numeric($providerScore)) {
                    $source = trim((string) ($seo['provider'] ?? 'wordpress'));
                    if ($source !== '') {
                        $scoreRows[] = [
                            'wordpress_id' => $wpId,
                            'source' => $source,
                            'score' => $providerScore,
                            'raw' => ['provider_score' => $providerScore, 'provider' => $source],
                        ];
                    }
                }

                // Only reconcile analysis links when item has explicit `links` key
                // (even empty). Do NOT infer zero links from body=null.
                if (array_key_exists('links', $item)) {
                    $rawLinks = is_array($item['links']) ? $item['links'] : [];
                    $analysisLinkJobs[] = [
                        'article' => $article,
                        'links' => array_values(array_filter(
                            $rawLinks,
                            static fn (mixed $row): bool => is_array($row),
                        )),
                    ];
                }
            } catch (Throwable $e) {
                $failed++;
                RuntimeLogger::warning('site_sync.v3_import_item_failed', [
                    'site_id' => (int) $site->id,
                    'run_id' => (int) $run->id,
                    'wp_id' => $wpId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $analysisLinkCount = $this->reconcileAnalysisLinks($site, $run, $analysisLinkJobs);

        $linkCounts = $linkRows === []
            ? ['upserted' => 0]
            : $this->links->reconcileWordPressLinks($site, $linkRows);
        $keywordCounts = $keywordRows === []
            ? ['provider_updated' => 0]
            : $this->keywords->reconcile($site, $keywordRows);
        $scoreCounts = $scoreRows === []
            ? ['upserted' => 0]
            : $this->scores->reconcile($site, $scoreRows);

        return [
            'upsert_count' => $upserted,
            'delete_count' => $deleted,
            'failed' => $failed,
            'links' => (int) ($linkCounts['upserted'] ?? 0) + $analysisLinkCount,
            'keywords' => (int) ($keywordCounts['provider_updated'] ?? 0),
            'scores' => (int) ($scoreCounts['upserted'] ?? 0),
        ];
    }

    /**
     * Persist analysis links into seo_link_maps for source articles.
     * Link href stays on the map (target_external_url / context_after).
     * Keyword rows only from eligible anchor_text — never from href.
     *
     * @param  list<array{article: SeoArticle, links: list<array<string, mixed>>}>  $jobs
     */
    private function reconcileAnalysisLinks(Site $site, SeoSiteSyncRun $run, array $jobs): int
    {
        if ($jobs === [] || ! Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            return 0;
        }

        $targetWpIds = [];
        foreach ($jobs as $job) {
            foreach ($job['links'] as $link) {
                $tid = (int) ($link['target_wp_id'] ?? $link['target_post_id'] ?? 0);
                if ($tid > 0) {
                    $targetWpIds[$tid] = $tid;
                }
            }
        }

        $targetArticleByWpId = [];
        if ($targetWpIds !== []) {
            $targets = SeoArticle::query()
                ->where('site_id', (int) $site->id)
                ->whereWpPostIdIn(array_values($targetWpIds))
                ->with('wordpressLink')
                ->get();
            foreach ($targets as $target) {
                $linkWpId = (int) ($target->wordpressLink?->wp_post_id ?? 0);
                if ($linkWpId > 0) {
                    $targetArticleByWpId[$linkWpId] = (int) $target->id;
                }
            }
        }

        $ctxBase = [
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'user_id' => (int) $site->user_id,
        ];

        $saved = 0;
        foreach ($jobs as $job) {
            $article = $job['article'];
            $sourceId = (int) $article->id;

            // Delete+reinsert sync-marker maps for this article (preserve non-sync maps).
            SeoLinkMap::query()
                ->where('source_article_id', $sourceId)
                ->where('context_before', self::LINK_SYNC_MARKER)
                ->delete();

            $seenHref = [];
            foreach ($job['links'] as $link) {
                $href = trim((string) ($link['href'] ?? $link['url'] ?? $link['href_raw'] ?? ''));
                if ($href === '') {
                    continue;
                }

                $hrefHash = hash('sha256', $href);
                if (isset($seenHref[$hrefHash])) {
                    continue;
                }
                $seenHref[$hrefHash] = true;

                $kind = strtolower(trim((string) ($link['kind'] ?? $link['link_type'] ?? 'external')));
                $isInternal = str_starts_with($kind, 'internal');
                $linkType = $isInternal ? SeoLinkMapType::Internal : SeoLinkMapType::External;

                $rawAnchor = trim((string) ($link['anchor_text'] ?? ''));
                $keywordId = null;
                $anchorForStorage = $rawAnchor;

                // Persist every valid href. Keyword attach is optional.
                if ($rawAnchor === '') {
                    RuntimeLogger::warning('site_sync.keyword_candidate_skipped', [
                        ...$ctxBase,
                        'source' => SiteSyncSchema::SOURCE_PROVIDER,
                        'candidate_type' => SiteSyncKeywordCandidateEvaluator::CANDIDATE_HREF,
                        'raw_value' => '',
                        'normalized_value' => '',
                        'phrase_kind' => 'url_domain',
                        'reason' => 'empty_anchor_href_not_promoted',
                        'href' => mb_substr($href, 0, 200),
                    ]);
                } else {
                    $evaluator = new SiteSyncKeywordCandidateEvaluator();
                    if ($evaluator->looksLikeUrlOrDomain($rawAnchor)) {
                        RuntimeLogger::warning('site_sync.keyword_candidate_skipped', [
                            ...$ctxBase,
                            'source' => SiteSyncSchema::SOURCE_PROVIDER,
                            'candidate_type' => SiteSyncKeywordCandidateEvaluator::CANDIDATE_ANCHOR,
                            'raw_value' => mb_substr($rawAnchor, 0, 200),
                            'normalized_value' => mb_substr(Keyword::preparePhraseForStorage($rawAnchor), 0, 200),
                            'phrase_kind' => 'url_domain',
                            'reason' => 'url_shaped_anchor_not_promoted',
                            'href' => mb_substr($href, 0, 200),
                        ]);
                    } else {
                        $keyword = $this->canonicalKeywords->findOrAttachEligible(
                            $rawAnchor,
                            SiteSyncKeywordCandidateEvaluator::CANDIDATE_ANCHOR,
                            SiteSyncSchema::SOURCE_PROVIDER,
                            [...$ctxBase, 'href' => $href, 'raw_value' => $rawAnchor],
                        );
                        if ($keyword instanceof Keyword) {
                            $keywordId = (int) $keyword->id;
                            $prepared = Keyword::preparePhraseForStorage($rawAnchor);
                            $anchorForStorage = $prepared !== '' ? $prepared : $rawAnchor;
                        }
                    }
                }

                $targetWpId = (int) ($link['target_wp_id'] ?? $link['target_post_id'] ?? 0);
                $targetArticleId = $targetWpId > 0 ? ($targetArticleByWpId[$targetWpId] ?? null) : null;

                // Never trust WP "internal" alone — same-site target only.
                if ($targetArticleId !== null) {
                    $linkType = SeoLinkMapType::Internal;
                } elseif ($isInternal) {
                    // WP claimed internal but no same-site article — keep URL as external/unresolved.
                    $linkType = SeoLinkMapType::External;
                    $targetArticleId = null;
                }

                $targetExternalUrl = $linkType === SeoLinkMapType::Internal && $targetArticleId !== null
                    ? null
                    : $href;

                SeoLinkMap::query()->create([
                    'keyword_id' => $keywordId,
                    'source_article_id' => $sourceId,
                    'target_article_id' => $targetArticleId,
                    'target_external_url' => $targetExternalUrl,
                    'anchor_text' => $anchorForStorage !== '' ? $anchorForStorage : '',
                    'context_before' => self::LINK_SYNC_MARKER,
                    'context_after' => $hrefHash,
                    'link_type' => $linkType,
                    'status' => SeoLinkMapStatus::Active,
                ]);
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function stripForbiddenBodyKeys(array $item): array
    {
        foreach (SiteSyncV3Schema::FORBIDDEN_BODY_KEYS as $key) {
            unset($item[$key]);
        }

        if (isset($item['scoring']) && is_array($item['scoring'])) {
            unset($item['scoring']['body'], $item['scoring']['content'], $item['scoring']['html']);
        }

        if (isset($item['seo']) && is_array($item['seo'])) {
            unset($item['seo']['body'], $item['seo']['post_content'], $item['seo']['content']);
        }

        return $item;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<int, SeoArticle>
     */
    private function preloadByWpPostIds(Site $site, array $items): array
    {
        $wpIds = [];
        foreach ($items as $item) {
            $wpId = (int) ($item['wp_id'] ?? $item['wordpress_id'] ?? 0);
            if ($wpId > 0) {
                $wpIds[$wpId] = $wpId;
            }
        }

        if ($wpIds === []) {
            return [];
        }

        $map = [];
        $articles = SeoArticle::query()
            ->where('site_id', (int) $site->id)
            ->whereWpPostIdIn(array_values($wpIds))
            ->with(['articleMetas', 'wordpressLink'])
            ->get();

        foreach ($articles as $article) {
            $linkWpId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            if ($linkWpId > 0) {
                $map[$linkWpId] = $article;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, SeoArticle>  $byWpId
     */
    private function upsertIdentity(
        Site $site,
        array $item,
        int $wpId,
        bool $isTermResource,
        array &$byWpId,
        int $generation,
    ): ?SeoArticle {
        if ($isTermResource && ! array_key_exists('wp_is_term', $item)) {
            $item['wp_is_term'] = true;
        }

        $classification = ArticleContentClassification::fromSyncItem($item, $site);
        $existing = $byWpId[$wpId] ?? null;

        if ($existing === null) {
            $existing = SeoArticle::query()
                ->where('site_id', (int) $site->id)
                ->whereWpPostId($wpId)
                ->first();
        }

        $title = trim((string) ($item['title'] ?? $item['post_title'] ?? ''));
        $status = $this->normalizeStatus((string) ($item['status'] ?? 'draft'));

        // Identity fields always upserted (even if content_hash unchanged).
        // body MUST stay null for WP-backed — never set from payload.
        $slugFromWp = isset($item['slug']) ? trim((string) $item['slug']) : '';
        $attrs = [
            'title' => $title !== '' ? $title : ($existing?->title ?: 'Untitled'),
            'status' => $status,
        ];
        // Persist WP slug when present; never invent when WP returns blank (draft).
        if ($slugFromWp !== '') {
            $attrs['slug'] = $slugFromWp;
        }

        if ($existing instanceof SeoArticle) {
            // Do not touch body/blocks/excerpt on update.
            $existing->fill($attrs)->save();
            $article = $existing;
        } else {
            $article = SeoArticle::query()->create(array_merge(
                [
                    'site_id' => (int) $site->id,
                    'body' => null,
                    'blocks' => null,
                    'excerpt' => null,
                    'slug' => $slugFromWp !== '' ? $slugFromWp : null,
                ],
                $attrs,
            ));
        }

        $article->forceFill([
            'wp_post_id' => $wpId,
            'published_at' => $this->parsePublishedAt($item['published_at'] ?? null),
        ])->save();

        ArticleContentClassification::persist($article, [
            'content_type' => $classification['content_type'],
            'wp_is_term' => $classification['wp_is_term'],
            'wp_post_type' => $classification['wp_post_type'],
        ]);

        if ($title !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_post_title'],
                ['meta_value' => $title],
            );
        }

        $permalink = trim((string) ($item['url'] ?? $item['permalink'] ?? ''));
        if ($permalink !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_permalink'],
                ['meta_value' => $permalink],
            );
        }

        // Explicitly refuse content-body meta keys.
        $article->articleMetas()->whereIn('meta_key', [
            'wp_post_content',
            'wp_post_content_filtered',
            'wp_post_content_hash',
        ])->delete();

        $linkAttrs = [
            'wp_post_id' => $wpId,
            'site_id' => (int) $site->id,
            'last_synced_at' => now(),
        ];
        if ($this->hasLastSeenSyncGenerationColumn()) {
            $linkAttrs['last_seen_sync_generation'] = $generation;
        }
        $this->linkWriter->upsert($article, $linkAttrs);
        $article->unsetRelation('wordpressLink');
        $this->lastSaved->touchSynced($article);

        return $article->fresh(['articleMetas', 'wordpressLink']);
    }

    /**
     * @param  array<int, SeoArticle>  $byWpId
     */
    private function deleteWpBackedOnly(Site $site, int $wpId, array &$byWpId): bool
    {
        $article = $byWpId[$wpId] ?? SeoArticle::query()
            ->where('site_id', (int) $site->id)
            ->whereWpPostId($wpId)
            ->first();

        if (! $article instanceof SeoArticle) {
            return false;
        }

        $hasLink = WordpressArticleLink::query()
            ->where('article_id', (int) $article->id)
            ->where('wp_post_id', $wpId)
            ->exists();

        if (! $hasLink) {
            // Not WP-backed — leave local-only articles alone.
            return false;
        }

        if (! $article->trashed()) {
            $article->delete();
        }
        unset($byWpId[$wpId]);

        return true;
    }

    private function syncGeneration(SeoSiteSyncRun $run): int
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $fromMeta = (int) ($meta['sync_generation'] ?? $meta['discover']['sync_generation'] ?? 0);
        if ($fromMeta > 0) {
            return $fromMeta;
        }

        return max(1, (int) $run->id);
    }

    private function hasLastSeenSyncGenerationColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $cached = Schema::connection('omi_seo_ai')->hasTable('wordpress_article_links')
            && Schema::connection('omi_seo_ai')->hasColumn('wordpress_article_links', 'last_seen_sync_generation');

        return $cached;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'publish', 'published' => 'publish',
            'draft' => 'draft',
            'pending' => 'pending',
            'private' => 'private',
            'trash', 'trashed' => 'trash',
            default => $status !== '' ? $status : 'draft',
        };
    }

    private function parsePublishedAt(mixed $raw): ?\Illuminate\Support\Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $raw);
        } catch (Throwable) {
            return null;
        }
    }
}
