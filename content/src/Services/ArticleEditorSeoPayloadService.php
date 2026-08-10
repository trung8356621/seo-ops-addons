<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleMetaMap;
use Omnichannel\Addons\Seo\Support\SeoRuleViolationsResolver;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;

final class ArticleEditorSeoPayloadService
{
    public function __construct(
        private readonly ArticleContentSeoBonusService $contentBonus,
    ) {}

    /**
     * Payload SEO cho editor / popup (tab Điểm SEO).
     *
     * @return array<string, mixed>
     */
    public function forArticle(SeoArticle $article): array
    {
        $article->loadMissing(['articleMetas', 'site', 'linkMaps', 'faqs']);

        $violations = SeoRuleViolationsResolver::forArticle($article);
        $score = SeoRuleViolationsResolver::scoreForArticle($article);
        $extractedLinks = $article->resolveExtractedLinks();
        $bodyHtml = app(SeoAnalyzerService::class)->resolveScoringContentForArticle($article);
        $internalLinks = $extractedLinks['internal'] ?? [];
        $externalLinks = $extractedLinks['external'] ?? [];
        // Phase 2 perf: one collectCandidates() pass instead of 4 (suggest/suggestCatalog/
        // suggestExternal/suggestExternalCatalog each re-ran the same keyword scan).
        $suggestionBundle = app(ArticleInternalLinkSuggestionService::class)->suggestBundle(
            $article,
            $bodyHtml,
            $internalLinks,
            $externalLinks,
        );
        $suggestedInternalLinks = $suggestionBundle['internal'];
        $suggestedInternalLinksCatalog = $suggestionBundle['internal_catalog'];
        $suggestedExternalLinks = $suggestionBundle['external'];
        $suggestedExternalLinksCatalog = $suggestionBundle['external_catalog'];
        $contentBonus = $this->contentBonus->resolveForArticle($article);

        $skipSeoScore = ! $article->countsTowardSeoScore();

        $seoTitle = trim((string) ($article->title ?? ''));

        $seoDescription = trim((string) (
            $article->articleMetas->first(
                static fn ($meta): bool => in_array((string) $meta->meta_key, [
                    'seo_meta_description',
                    'meta_description',
                ], true),
            )?->meta_value ?? ''
        ));

        $googleSerpPreview = app(ArticleGoogleSerpPreviewService::class)->buildForArticle(
            $article,
            $seoTitle,
            $seoDescription,
            app(WordPressArticleContentService::class)->resolvePermalink($article) ?: '',
        );

        $analysis = [
            'violations' => $violations,
            'score' => $score,
        ];

        return [
            'focus_keyword' => app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article),
            'site_domain' => trim((string) ($article->site?->domain ?? '')),
            'article_type' => (string) ($article->type ?? 'post'),
            'skip_seo_score' => $skipSeoScore,
            'violations' => $violations,
            'score' => $skipSeoScore ? null : $score,
            'analysis' => $analysis,
            'content_bonus' => $contentBonus,
            'extracted_links' => $extractedLinks,
            'suggested_internal_links' => $suggestedInternalLinks,
            'suggested_internal_links_catalog' => $suggestedInternalLinksCatalog,
            'suggested_external_links' => $suggestedExternalLinks,
            'suggested_external_links_catalog' => $suggestedExternalLinksCatalog,
            'google_serp_preview' => $googleSerpPreview,
            'article_slug' => trim((string) ($article->slug ?? '')),
            'permalink_base' => $article->site
                ? rtrim(app(WordPressArticleContentService::class)->getPermalinkBase($article->site), '/')
                : '',
            'domain_link_list_catalog' => app(DomainLinkListEditorService::class)->forSite($article->site),
            'domain_link_list' => app(DomainLinkListEditorService::class)->forArticle($article, $bodyHtml),
            'domain_cta_list' => app(DomainCtaEditorService::class)->forSite($article->site),
            'seo_scoring_rules' => SeoScoringRulesRegistry::publicRulesForClient(),
            'seo_rule_messages' => SeoScoringRulesRegistry::messagesForLocale(),
            'seo_scoring_messages' => SeoScoringRulesRegistry::messagesForLocale(),
        ];
    }

    /**
     * Light SEO payload for editor first paint — cached score/violations only, no link catalogs or re-analysis.
     *
     * @return array<string, mixed>
     */
    public function forEditorBootstrap(SeoArticle $article): array
    {
        $article->loadMissing(['articleMetas', 'site']);

        $violations = SeoRuleViolationsResolver::forArticle($article);
        $score = SeoRuleViolationsResolver::scoreForArticle($article);
        $skipSeoScore = ! $article->countsTowardSeoScore();
        $bodyHtml = (string) ($article->body ?? '');

        $seoTitle = trim((string) ($article->title ?? ''));

        $seoDescription = trim((string) (
            $article->articleMetas->first(
                static fn ($meta): bool => in_array((string) $meta->meta_key, [
                    'seo_meta_description',
                    'meta_description',
                ], true),
            )?->meta_value ?? ''
        ));

        $wpContent = app(WordPressArticleContentService::class);
        $cachedPermalink = trim((string) (
            $article->articleMetas->first(
                static fn ($meta): bool => (string) $meta->meta_key === 'wp_permalink',
            )?->meta_value ?? ''
        ));
        $localPermalink = app(WordPressPermalinkBuilder::class)->resolve(
            $article,
            $cachedPermalink,
            $wpContent->resolveSlug($article),
        );

        $googleSerpPreview = app(ArticleGoogleSerpPreviewService::class)->buildForArticle(
            $article,
            $seoTitle,
            $seoDescription,
            $localPermalink,
        );

        $analysis = [
            'violations' => $violations,
            'score' => $score,
        ];

        $analyzedContentHash = $this->resolveAnalyzedContentHash($article, $bodyHtml);

        return [
            'score' => $skipSeoScore ? null : $score,
            'status' => 'cached',
            'analyzed_content_hash' => $analyzedContentHash,
            'score_version' => trim((string) (
                $article->articleMetas->first(
                    static fn ($meta): bool => (string) $meta->meta_key === SeoScoringRulesRegistry::META_KEY_SCORE_VERSION,
                )?->meta_value ?? ''
            )) ?: SeoScoringRulesRegistry::SCORE_VERSION,
            'score_calculated_at' => trim((string) (
                $article->articleMetas->first(
                    static fn ($meta): bool => (string) $meta->meta_key === SeoScoringRulesRegistry::META_KEY_SCORE_CALCULATED_AT,
                )?->meta_value ?? ''
            )) ?: null,
            'focus_keyword' => app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article),
            'seo_title' => $seoTitle,
            'meta_description' => $seoDescription,
            'updated_at' => $article->updated_at?->toIso8601String(),
            'site_domain' => trim((string) ($article->site?->domain ?? '')),
            'article_type' => (string) ($article->type ?? 'post'),
            'skip_seo_score' => $skipSeoScore,
            'article_slug' => trim((string) ($article->slug ?? '')),
            'permalink_base' => $article->site
                ? rtrim($wpContent->getPermalinkBase($article->site), '/')
                : '',
            'google_serp_preview' => $googleSerpPreview,
            'analysis' => $analysis,
            'violations' => $violations,
            'extracted_links' => ['internal' => [], 'external' => []],
            'suggested_internal_links' => [],
            'suggested_internal_links_catalog' => [],
            'suggested_external_links' => [],
            'suggested_external_links_catalog' => [],
            'domain_link_list' => [],
            'domain_link_list_catalog' => [],
            'domain_cta_list' => [],
            'content_bonus' => null,
            'bootstrap_mode' => 'light',
        ];
    }

    /**
     * Minimal SEO summary for editor first paint / lazy refresh — score, focus keyword,
     * title/description, permalink base only. No link catalogs, no SERP preview rebuild,
     * no domain lists (Phase 2: those live in ArticleEditorLinksPayloadService + settings).
     *
     * @return array<string, mixed>
     */
    public function forEditorSeoSummary(SeoArticle $article): array
    {
        $article->loadMissing(['articleMetas', 'site']);

        $metaMap = ArticleMetaMap::for($article);
        $violations = SeoRuleViolationsResolver::forArticle($article);
        $score = SeoRuleViolationsResolver::scoreForArticle($article);
        $skipSeoScore = ! $article->countsTowardSeoScore();
        $bodyHtml = (string) ($article->body ?? '');

        $seoTitle = trim((string) ($article->title ?? ''));
        $seoDescription = trim((string) $metaMap->getAny(['seo_meta_description', 'meta_description'], ''));

        $wpContent = app(WordPressArticleContentService::class);
        $cachedPermalink = trim((string) $metaMap->get('wp_permalink', ''));
        $localPermalink = app(WordPressPermalinkBuilder::class)->resolve(
            $article,
            $cachedPermalink,
            $wpContent->resolveSlug($article),
        );

        return [
            'score' => $skipSeoScore ? null : $score,
            'status' => 'cached',
            'stale' => false,
            'skip_seo_score' => $skipSeoScore,
            'analyzed_content_hash' => $this->resolveAnalyzedContentHash($article, $bodyHtml),
            'score_version' => trim((string) $metaMap->get(SeoScoringRulesRegistry::META_KEY_SCORE_VERSION, '')) ?: SeoScoringRulesRegistry::SCORE_VERSION,
            'score_calculated_at' => trim((string) $metaMap->get(SeoScoringRulesRegistry::META_KEY_SCORE_CALCULATED_AT, '')) ?: null,
            'focus_keyword' => app(SeoAnalyzerService::class)->resolveFocusKeywordForArticle($article),
            'seo_title' => $seoTitle,
            'meta_description' => $seoDescription,
            'content_hash' => hash('sha256', trim($bodyHtml)),
            'updated_at' => $article->updated_at?->toIso8601String(),
            'site_domain' => trim((string) ($article->site?->domain ?? '')),
            'article_slug' => trim((string) ($article->slug ?? '')),
            'permalink_base' => $article->site
                ? rtrim($wpContent->getPermalinkBase($article->site), '/')
                : '',
            'permalink' => $localPermalink,
            'violations' => $violations,
        ];
    }

    private function resolveAnalyzedContentHash(SeoArticle $article, string $bodyHtml): string
    {
        $fromMeta = trim((string) (
            $article->articleMetas->first(
                static fn ($meta): bool => (string) $meta->meta_key === SeoScoringRulesRegistry::META_KEY_ANALYZED_CONTENT_HASH,
            )?->meta_value ?? ''
        ));

        if ($fromMeta !== '') {
            return $fromMeta;
        }

        return hash('sha256', trim($bodyHtml));
    }
}
