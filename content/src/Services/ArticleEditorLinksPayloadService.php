<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Links sidebar payload split out of ArticleEditorSeoPayloadService::forArticle()
 * (Phase 2 perf) — the Links panel must never call the full forArticle() bundle
 * (violations/score/analysis/SERP preview are irrelevant to it).
 */
final class ArticleEditorLinksPayloadService
{
    public function __construct(
        private readonly ArticleInternalLinkSuggestionService $suggestionService,
    ) {}

    /**
     * Extracted links (from body) + domain link/CTA lists — no keyword suggestions.
     *
     * @return array<string, mixed>
     */
    public function base(SeoArticle $article): array
    {
        $article->loadMissing('site');
        $bodyHtml = $this->resolveSuggestionContent($article, null);
        $extractedLinks = $article->resolveExtractedLinks();

        return [
            'extracted_links' => $extractedLinks,
            'domain_link_list' => app(DomainLinkListEditorService::class)->forArticle($article, $bodyHtml),
            'domain_link_list_catalog' => app(DomainLinkListEditorService::class)->forSite($article->site),
            'domain_cta_list' => app(DomainCtaEditorService::class)->forSite($article->site),
            'cta_quick_templates' => app(DomainCtaEditorService::class)->quickTemplates(),
            'suggested_internal_links' => [],
            'suggested_internal_links_catalog' => [],
            'suggested_external_links' => [],
            'suggested_external_links_catalog' => [],
            'can_generate_suggestions' => true,
            'counts' => [
                'internal' => count($extractedLinks['internal'] ?? []),
                'external' => count($extractedLinks['external'] ?? []),
            ],
        ];
    }

    /**
     * base() + one suggestBundle() pass for internal/external keyword suggestions.
     *
     * @return array<string, mixed>
     */
    public function withSuggestions(SeoArticle $article, ?string $submittedContent = null): array
    {
        $content = $this->resolveSuggestionContent($article, $submittedContent);
        $base = $this->base($article);
        $internalLinks = $base['extracted_links']['internal'] ?? [];
        $externalLinks = $base['extracted_links']['external'] ?? [];

        $bundle = $this->suggestionService->suggestBundle($article, $content, $internalLinks, $externalLinks);

        $payload = array_merge($base, [
            'suggested_internal_links' => $bundle['internal'],
            'suggested_internal_links_catalog' => $bundle['internal_catalog'],
            'suggested_external_links' => $bundle['external'],
            'suggested_external_links_catalog' => $bundle['external_catalog'],
            'content_source' => $this->describeContentSource($article, $submittedContent, $content),
        ]);

        if (isset($bundle['debug']) && is_array($bundle['debug'])) {
            $payload['suggestion_debug'] = $bundle['debug'];
        }

        return $payload;
    }

    /**
     * Chỉ chạy content-keyword fallback — nút debug «Tạo gợi ý bổ sung».
     *
     * @param  list<array<string, mixed>>  $existingInternal
     * @return array<string, mixed>
     */
    public function withFallbackOnly(
        SeoArticle $article,
        ?string $submittedContent = null,
        array $existingInternal = [],
    ): array {
        $content = $this->resolveSuggestionContent($article, $submittedContent);
        $base = $this->base($article);
        $internalLinks = $base['extracted_links']['internal'] ?? [];
        $externalLinks = $base['extracted_links']['external'] ?? [];

        $bundle = $this->suggestionService->suggestFallbackSupplement(
            $article,
            $content,
            $existingInternal,
            $internalLinks,
            $externalLinks,
        );

        $payload = array_merge($base, [
            'suggested_internal_links' => $bundle['internal'],
            'suggested_internal_links_catalog' => $bundle['internal_catalog'],
            'suggested_external_links' => $bundle['external'],
            'suggested_external_links_catalog' => $bundle['external_catalog'],
            'content_source' => $this->describeContentSource($article, $submittedContent, $content),
        ]);

        if (isset($bundle['debug']) && is_array($bundle['debug'])) {
            $payload['suggestion_debug'] = $bundle['debug'];
        }

        return $payload;
    }

    /**
     * Content thật cho suggestion: submitted editor HTML → articles.body → wp_post_content.
     */
    public function resolveSuggestionContent(SeoArticle $article, ?string $submittedContent): string
    {
        $submitted = trim((string) $submittedContent);
        if ($submitted !== '') {
            return $submitted;
        }

        return app(SeoAnalyzerService::class)->resolveScoringContentForArticle($article);
    }

    private function describeContentSource(SeoArticle $article, ?string $submitted, string $resolved): string
    {
        if (trim((string) $submitted) !== '') {
            return 'client_editor_html';
        }

        if (trim((string) ($article->body ?? '')) !== '') {
            return 'articles.body';
        }

        $article->loadMissing('articleMetas');
        $meta = $article->articleMetas->firstWhere('meta_key', 'wp_post_content');
        if ($meta && trim((string) ($meta->meta_value ?? '')) !== '') {
            return 'article_meta.wp_post_content';
        }

        return mb_strlen($resolved) > 0 ? 'resolved_non_empty' : 'empty';
    }
}
