<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use App\Core\Capability\CapabilityRegistry;
use App\Models\Site;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Services\SeoMainDomainService;
use Omnichannel\Addons\SiteSync\Contracts\SiteLinkCatalogCapability;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;

final readonly class ArticleEditorMainDomainSuggestionService
{
    public function __construct(
        private CapabilityRegistry $capabilities,
        private SeoMainDomainService $mainDomains,
        private SeoAnalyzerService $seoAnalyzer,
        private WordPressArticleContentService $wordpressContent,
    ) {}

    /**
     * @return array{
     *     main_domain: string|null,
     *     main_site_id: int|null,
     *     relationship: 'internal'|'external'|null,
     *     source: string,
     *     items: list<array<string, mixed>>
     * }
     */
    public function forArticle(SeoArticle $article): array
    {
        $article->loadMissing(['site', 'wordpressLink']);
        $ownerId = (int) ($article->site?->user_id ?? 0);
        $mainSiteId = $this->mainDomains->primarySiteIdForOwner($ownerId);
        if ($mainSiteId === null) {
            return $this->emptyPayload();
        }

        $mainSite = Site::query()->find($mainSiteId);
        $catalog = $this->capabilities->getAs(
            SiteLinkCatalogCapability::ID,
            SiteLinkCatalogCapability::class,
        );
        if (! $mainSite instanceof Site || ! $catalog instanceof SiteLinkCatalogCapability) {
            return $this->emptyPayload($mainSite);
        }

        $relationship = (int) $article->site_id === $mainSiteId ? 'internal' : 'external';
        $currentUrl = $this->normalizeUrl($this->wordpressContent->resolvePermalink($article) ?: '');
        $currentWordPressId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        $terms = $this->articleTerms($article);
        $items = [];

        foreach ($catalog->effectiveLinks($mainSiteId) as $row) {
            $url = $this->candidateUrl($row);
            if ($url === '' || $this->normalizeUrl($url) === $currentUrl) {
                continue;
            }
            if ($currentWordPressId > 0 && (int) ($row['wordpress_id'] ?? 0) === $currentWordPressId) {
                continue;
            }
            if (! $this->isActiveCandidate($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? $row['slug'] ?? ''));
            if ($title === '') {
                $title = $this->titleFromUrl($url);
            }
            $items[] = [
                'text' => $title,
                'page_title' => $title,
                'href' => $url,
                'target_url' => $url,
                'relationship' => $relationship,
                'main_domain' => trim((string) $mainSite->domain),
                'source' => 'main_domain_site_sync_catalog',
                'catalog_source' => (string) ($row['source'] ?? ''),
                'importance_score' => $this->rank($row, $title, $terms),
                'can_insert' => true,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $score = (int) ($right['importance_score'] ?? 0) <=> (int) ($left['importance_score'] ?? 0);

            return $score !== 0
                ? $score
                : strcasecmp((string) ($left['page_title'] ?? ''), (string) ($right['page_title'] ?? ''));
        });

        return [
            'main_domain' => trim((string) $mainSite->domain) ?: null,
            'main_site_id' => $mainSiteId,
            'relationship' => $relationship,
            'source' => 'site-sync.link-catalog',
            'items' => array_slice($items, 0, 30),
        ];
    }

    /**
     * @return array{main_domain: string|null, main_site_id: int|null, relationship: null, source: string, items: array{}}
     */
    private function emptyPayload(?Site $mainSite = null): array
    {
        return [
            'main_domain' => $mainSite !== null ? trim((string) $mainSite->domain) ?: null : null,
            'main_site_id' => $mainSite !== null ? (int) $mainSite->id : null,
            'relationship' => null,
            'source' => 'site-sync.link-catalog',
            'items' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function candidateUrl(array $row): string
    {
        $canonical = trim((string) ($row['canonical'] ?? ''));

        return filter_var($canonical, FILTER_VALIDATE_URL) !== false
            ? $canonical
            : trim((string) ($row['url'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isActiveCandidate(array $row): bool
    {
        $status = strtolower(trim((string) ($row['status'] ?? 'publish')));

        return in_array($status, ['', 'active', 'manual', 'publish', 'published'], true);
    }

    /**
     * @return list<string>
     */
    private function articleTerms(SeoArticle $article): array
    {
        $focus = $this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? '';
        $source = implode(' ', [
            (string) ($article->title ?? ''),
            (string) ($article->slug ?? ''),
            $focus,
        ]);

        return $this->tokens($source);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $terms
     */
    private function rank(array $row, string $title, array $terms): int
    {
        $candidateTokens = $this->tokens($title.' '.(string) ($row['slug'] ?? ''));
        $overlap = count(array_intersect($terms, $candidateTokens));
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $importance = max(0, (int) ($meta['importance'] ?? $meta['priority'] ?? 0));
        $type = strtolower(trim((string) ($row['type'] ?? '')));
        $typeBoost = in_array($type, ['home', 'homepage', 'page', 'landing_page'], true) ? 20 : 0;
        $manualBoost = (string) ($row['source'] ?? '') === 'manual' ? 15 : 0;

        return min(1000, ($overlap * 25) + ($importance * 10) + $typeBoost + $manualBoost);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $normalized = mb_strtolower(trim(strip_tags($value)));
        preg_match_all('/[\p{L}\p{N}]{3,}/u', $normalized, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function normalizeUrl(string $url): string
    {
        $normalized = mb_strtolower(trim($url));

        return rtrim($normalized, '/');
    }

    private function titleFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        }

        return str_replace(['-', '_'], ' ', basename($path));
    }
}
