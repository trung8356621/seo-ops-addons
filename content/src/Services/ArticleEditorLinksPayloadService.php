<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentSchema;
use Omnichannel\Addons\Seo\Services\DomainCtaEditorService;
use Omnichannel\Addons\Seo\Services\DomainLinkListEditorService;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;

/**
 * Links sidebar payload split out of ArticleEditorSeoPayloadService::forArticle()
 * (Phase 2 perf) — the Links panel must never call the full forArticle() bundle
 * (violations/score/analysis/SERP preview are irrelevant to it).
 */
final class ArticleEditorLinksPayloadService
{
    public function __construct(
        private readonly ArticleInternalLinkSuggestionService $suggestionService,
        private readonly ArticleEditorMainDomainSuggestionService $mainDomainSuggestions,
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
            'main_domain_suggestions' => $this->mainDomainSuggestions->forArticle($article),
            'suggested_internal_links' => [],
            'suggested_internal_links_catalog' => [],
            'suggested_external_links' => [],
            'suggested_external_links_catalog' => [],
            'internal_link_catalog' => [],
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
            'internal_link_catalog' => is_array($bundle['internal_link_catalog'] ?? null)
                ? $bundle['internal_link_catalog']
                : [],
            'content_source' => $this->describeContentSource($article, $submittedContent, $content),
        ]);
        $payload = $this->mergeSemanticSuggestions($article, $content, $payload);

        if (isset($bundle['debug']) && is_array($bundle['debug'])) {
            $payload['suggestion_debug'] = $bundle['debug'];
        }

        return $payload;
    }

    /**
     * «Tìm thêm gợi ý» — cùng staged pipeline, loại URL đã hiện.
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
            'internal_link_catalog' => is_array($bundle['internal_link_catalog'] ?? null)
                ? $bundle['internal_link_catalog']
                : [],
            'content_source' => $this->describeContentSource($article, $submittedContent, $content),
        ]);

        if (isset($bundle['debug']) && is_array($bundle['debug'])) {
            $payload['suggestion_debug'] = $bundle['debug'];
        }

        return $payload;
    }

    /**
     * «Tìm kiếm nâng cao» — same staged pipeline with stage+offset resume.
     *
     * @param  list<array<string, mixed>>  $existingInternal
     * @param  list<string>  $failedKeys
     * @param  array{stage?: string, offset?: int}  $cursor
     * @return array<string, mixed>
     */
    public function withAdvancedBatch(
        SeoArticle $article,
        ?string $submittedContent = null,
        array $existingInternal = [],
        array $failedKeys = [],
        array $cursor = [],
        int $targetCount = 5,
        int $usableCount = -1,
    ): array {
        $content = $this->resolveSuggestionContent($article, $submittedContent);
        $base = $this->base($article);
        $internalLinks = $base['extracted_links']['internal'] ?? [];
        $externalLinks = $base['extracted_links']['external'] ?? [];

        $bundle = $this->suggestionService->suggestAdvancedBatch(
            $article,
            $content,
            $existingInternal,
            $internalLinks,
            $externalLinks,
            $failedKeys,
            $cursor,
            $targetCount,
            $usableCount,
        );

        $payload = array_merge($base, [
            'suggested_internal_links' => $bundle['internal'],
            'suggested_internal_links_catalog' => $bundle['internal_catalog'],
            'suggested_external_links' => $bundle['external'],
            'suggested_external_links_catalog' => $bundle['external_catalog'],
            'internal_link_catalog' => is_array($bundle['internal_link_catalog'] ?? null)
                ? $bundle['internal_link_catalog']
                : [],
            'suggestion_cursor' => $bundle['cursor'],
            'suggestions_exhausted' => (bool) ($bundle['exhausted'] ?? false),
            'failed_candidate_keys' => is_array($bundle['failed_keys'] ?? null) ? $bundle['failed_keys'] : [],
            'content_source' => $this->describeContentSource($article, $submittedContent, $content),
        ]);

        if (isset($bundle['debug']) && is_array($bundle['debug'])) {
            $payload['suggestion_debug'] = $bundle['debug'];
        }

        return $payload;
    }

    /**
     * Content thật cho suggestion: submitted editor HTML, rồi articles.body, rồi editor_document.
     */
    public function resolveSuggestionContent(SeoArticle $article, ?string $submittedContent): string
    {
        $submitted = trim((string) $submittedContent);
        if ($submitted !== '') {
            return $submitted;
        }

        $body = app(SeoAnalyzerService::class)->resolveScoringContentForArticle($article);
        if (trim($body) !== '') {
            return $body;
        }

        $document = $article->editor_document;
        if (! is_array($document) || (int) ($document['schema_version'] ?? 0) < 1) {
            return '';
        }

        try {
            return app(ArticleEditorDocumentSchema::class)->renderHtml($document);
        } catch (\Throwable) {
            return '';
        }
    }

    private function describeContentSource(SeoArticle $article, ?string $submitted, string $resolved): string
    {
        if (trim((string) $submitted) !== '') {
            return 'client_editor_html';
        }

        if (trim((string) ($article->body ?? '')) !== '') {
            return 'articles.body';
        }

        if (is_array($article->editor_document) && trim($resolved) !== '') {
            return 'editor_document';
        }

        return 'empty';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeSemanticSuggestions(SeoArticle $article, string $content, array $payload): array
    {
        if (config('semantic.internal_link_v2') === true) {
            $internal = app(InternalLinkV2Suggester::class)->suggest(
                $article,
                $content,
                is_array($payload['extracted_links']['internal'] ?? null) ? $payload['extracted_links']['internal'] : [],
            );
            $payload['internal_link_v2'] = $internal;
            if (($internal['suggestions'] ?? []) !== []) {
                $payload['suggested_internal_links'] = array_merge(
                    $internal['suggestions'],
                    is_array($payload['suggested_internal_links'] ?? null) ? $payload['suggested_internal_links'] : [],
                );
            }
        }
        if (config('semantic.wiki_suggestions') === true) {
            $wiki = app(ExternalWikiSuggestionService::class)->suggest($article, $content);
            $payload['wiki_suggestions'] = $wiki;
            if (($wiki['suggestions'] ?? []) !== []) {
                $payload['suggested_external_links'] = array_merge(
                    $wiki['suggestions'],
                    is_array($payload['suggested_external_links'] ?? null) ? $payload['suggested_external_links'] : [],
                );
            }
        }

        return $payload;
    }
}
