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
            'suggestion_engines' => self::suggestionEngines(),
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
    public function withSuggestions(SeoArticle $article, ?string $submittedContent = null, ?string $scope = null): array
    {
        $scope = self::normalizeScope($scope);
        $content = $this->resolveSuggestionContent($article, $submittedContent);
        $base = $this->base($article);
        $internalLinks = $base['extracted_links']['internal'] ?? [];
        $externalLinks = $base['extracted_links']['external'] ?? [];

        $channels = self::channelsForScope($scope);
        $bundle = $channels['external']
            ? $this->suggestionService->suggestBundle(
                $article,
                $content,
                $internalLinks,
                $externalLinks,
                false,
                true,
            )
            : [
                'internal' => [],
                'internal_catalog' => [],
                'external' => [],
                'external_catalog' => [],
                'internal_link_catalog' => [],
            ];

        $payload = array_merge($base, [
            'suggested_internal_links' => $bundle['internal'],
            'suggested_internal_links_catalog' => $bundle['internal_catalog'],
            'suggested_external_links' => $bundle['external'],
            'suggested_external_links_catalog' => $bundle['external_catalog'],
            'internal_link_catalog' => is_array($bundle['internal_link_catalog'] ?? null)
                ? $bundle['internal_link_catalog']
                : [],
            'suggestion_scope' => $scope,
            'content_source' => $this->describeContentSource($article, $submittedContent, $content),
        ]);
        $payload = $this->mergeSemanticSuggestions($article, $content, $payload, $scope);

        if (isset($bundle['debug']) && is_array($bundle['debug'])) {
            $payload['suggestion_debug'] = $bundle['debug'];
        }

        return $this->publishSuggestionLists($payload);
    }

    /**
     * «Tìm thêm gợi ý» — cùng staged pipeline, loại URL đã hiện.
     *
     * @param  list<array<string, mixed>>  $existingInternal
     * @param  array<string, mixed>  $cursor
     * @return array<string, mixed>
     */
    public function withFallbackOnly(
        SeoArticle $article,
        ?string $submittedContent = null,
        array $existingInternal = [],
        array $cursor = [],
    ): array {
        $content = $this->resolveSuggestionContent($article, $submittedContent);
        $base = $this->base($article);
        $occupied = array_merge(
            is_array($base['extracted_links']['internal'] ?? null) ? $base['extracted_links']['internal'] : [],
            $existingInternal,
        );
        $internal = app(InternalLinkV2Suggester::class)->suggest($article, $content, $occupied, $cursor);
        $suggestions = is_array($internal['suggestions'] ?? null) ? $internal['suggestions'] : [];
        $exhausted = ($internal['discovery']['exhausted'] ?? false) === true;

        $payload = array_merge($base, [
            'suggested_internal_links' => $suggestions,
            'suggested_internal_links_catalog' => $suggestions,
            'suggested_external_links' => [],
            'suggested_external_links_catalog' => [],
            'internal_link_catalog' => [],
            'internal_link_v2' => $internal,
            'suggestion_status' => (string) ($internal['status'] ?? 'empty'),
            'suggestion_reason' => $internal['reason'] ?? null,
            'suggestions_exhausted' => $exhausted,
            'discovery_cursor' => is_array($internal['discovery'] ?? null) ? $internal['discovery'] : null,
            'content_source' => $this->describeContentSource($article, $submittedContent, $content),
        ]);

        return $this->publishSuggestionLists($payload);
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
        return $this->withFallbackOnly($article, $submittedContent, $existingInternal, $cursor);
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
    private function mergeSemanticSuggestions(SeoArticle $article, string $content, array $payload, ?string $scope = null): array
    {
        $scope = self::normalizeScope($scope);
        if ($scope !== 'external') {
            $internal = app(InternalLinkV2Suggester::class)->suggest(
                $article,
                $content,
                is_array($payload['extracted_links']['internal'] ?? null) ? $payload['extracted_links']['internal'] : [],
            );
            $payload['internal_link_v2'] = $internal;
            $payload['suggested_internal_links'] = $internal['suggestions'] ?? [];
            $payload['suggested_internal_links_catalog'] = $internal['suggestions'] ?? [];
            $payload['suggestion_status'] = (string) ($internal['status'] ?? 'empty');
            $payload['discovery_cursor'] = is_array($internal['discovery'] ?? null) ? $internal['discovery'] : null;
            $payload['suggestions_exhausted'] = ($internal['discovery']['exhausted'] ?? false) === true;
            if (is_string($internal['reason'] ?? null) && $internal['reason'] !== '') {
                $payload['suggestion_reason'] = $internal['reason'];
            }
        }
        if ($scope !== 'internal' && config('semantic.wiki_suggestions') === true) {
            $wiki = app(ExternalWikiSuggestionService::class)->suggest($article, $content);
            $payload['wiki_suggestions'] = $wiki;
            $payload['suggested_external_links'] = $wiki['suggestions'] ?? [];
            $payload['suggested_external_links_catalog'] = $wiki['suggestions'] ?? [];
        }

        return $this->publishSuggestionLists($payload);
    }

    /**
     * @return array{internal: string, external: string}
     */
    public static function suggestionEngines(): array
    {
        return [
            'internal' => 'semantic_v2',
            'external' => config('semantic.wiki_suggestions') === true ? 'wiki_v2' : 'legacy',
        ];
    }

    /**
     * Drop hash/fragment suggestion rows. Existing article anchors stay in extracted_links.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function publishSuggestionLists(array $payload): array
    {
        $engines = self::suggestionEngines();
        foreach (['suggested_internal_links', 'suggested_internal_links_catalog'] as $key) {
            $rows = is_array($payload[$key] ?? null) ? $payload[$key] : [];
            $payload[$key] = $this->stampSuggestionEngine(
                $this->withoutFragmentSuggestions($rows),
                $engines['internal'],
            );
        }
        foreach (['suggested_external_links', 'suggested_external_links_catalog'] as $key) {
            $rows = is_array($payload[$key] ?? null) ? $payload[$key] : [];
            $payload[$key] = $this->stampSuggestionEngine($rows, $engines['external']);
        }
        $payload['suggestion_engines'] = $engines;

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function stampSuggestionEngine(array $rows, string $engine): array
    {
        $stamped = [];
        foreach ($rows as $row) {
            $row['suggestion_engine'] = $engine;
            $stamped[] = $row;
        }

        return $stamped;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function withoutFragmentSuggestions(array $rows): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $href = trim((string) ($row['href'] ?? $row['target_url'] ?? ''));
            if ($href === '' || $href === '#' || str_starts_with($href, '#')) {
                continue;
            }
            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * Legacy collector runs only for a category whose V2 flag is off.
     *
     * @return array{internal: bool, external: bool}
     */
    public static function legacyChannels(): array
    {
        return [
            'internal' => false,
            'external' => config('semantic.wiki_suggestions') !== true,
        ];
    }

    public static function normalizeScope(?string $scope): string
    {
        $scope = strtolower(trim((string) $scope));

        return in_array($scope, ['internal', 'external'], true) ? $scope : 'both';
    }

    /**
     * Legacy collector for one category. The other category stays untouched.
     *
     * @return array{internal: bool, external: bool}
     */
    public static function channelsForScope(?string $scope = null): array
    {
        $legacy = self::legacyChannels();
        $scope = self::normalizeScope($scope);
        if ($scope === 'internal') {
            return ['internal' => $legacy['internal'], 'external' => false];
        }
        if ($scope === 'external') {
            return ['internal' => false, 'external' => $legacy['external']];
        }

        return $legacy;
    }
}
