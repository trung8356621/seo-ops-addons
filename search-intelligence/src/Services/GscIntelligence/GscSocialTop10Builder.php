<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPageMapping;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Deterministic GSC MCP → Top 10 Social articles.
 *
 * Reuses planning_signals priority from GscPlanningSignalNormalizer.
 * Excludes new_content_opportunity (no covered page / no share URL).
 * No AI.
 *
 * @phpstan-type SocialItem array{
 *   article_id: int,
 *   title: string,
 *   url: string,
 *   path: string,
 *   impressions: int,
 *   position: float|null,
 *   reason_tags: list<string>,
 *   queries: list<string>,
 *   signal_types: list<string>
 * }
 */
final class GscSocialTop10Builder
{
    public const MAX_ITEMS = 10;

    /** @var list<string> */
    public const EXCLUDED_SIGNAL_TYPES = [
        'new_content_opportunity',
    ];

    /** @var array<string, string> */
    private const REASON_TAGS = [
        'near_page_one' => 'Gần Top 10',
        'high_impression_low_ctr' => 'CTR thấp',
        'rising_query' => 'Đang tăng',
        'falling_query' => 'Đang giảm',
        'content_decay' => 'Content decay',
        'possible_cannibalization' => 'Cannibalization',
    ];

    public function __construct(
        private readonly GscPageNormalizationService $pageNormalizer,
        private readonly ?KeywordLinkTargetResolver $urlResolver = null,
    ) {}

    /**
     * @param  array<string, mixed>  $mcpPayload  built or stored MCP (metrics/summary/context)
     * @return array{
     *   items: list<SocialItem>,
     *   unmapped_pages: int,
     *   period_key: string,
     *   excluded_no_page: int
     * }
     */
    public function build(int $siteId, string $periodKey, array $mcpPayload): array
    {
        $signals = $this->extractSignals($mcpPayload);
        $excludedNoPage = 0;
        $unmappedPages = 0;
        /** @var array<int, SocialItem> $byArticle */
        $byArticle = [];
        /** @var array<string, true> $seenPages */
        $seenPages = [];
        /** @var array<string, int|null> $pageArticleCache normalized_page => article_id|null */
        $pageArticleCache = [];

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $type = (string) ($signal['type'] ?? '');
            if ($type === '' || in_array($type, self::EXCLUDED_SIGNAL_TYPES, true)) {
                if ($type === 'new_content_opportunity') {
                    $excludedNoPage++;
                }

                continue;
            }

            $page = $this->resolvePrimaryPage($signal);
            if ($page === '') {
                $excludedNoPage++;

                continue;
            }

            $normalized = $this->pageNormalizer->normalize($page)['normalized_url'];
            if ($normalized === '') {
                $unmappedPages++;

                continue;
            }

            if (! array_key_exists($normalized, $pageArticleCache)) {
                $mapped = $this->mapPageToArticle($siteId, $normalized, $page);
                $pageArticleCache[$normalized] = $mapped['article_id'] ?? null;
                if ($pageArticleCache[$normalized] === null) {
                    $unmappedPages++;
                    if (! isset($seenPages[$normalized])) {
                        $seenPages[$normalized] = true;
                    }

                    continue;
                }
            } elseif ($pageArticleCache[$normalized] === null) {
                continue;
            }

            $articleId = (int) $pageArticleCache[$normalized];
            if ($articleId <= 0) {
                continue;
            }

            $query = trim((string) ($signal['query'] ?? ''));
            $metrics = is_array($signal['metrics'] ?? null) ? $signal['metrics'] : [];
            $impressions = (int) ($metrics['impressions'] ?? 0);
            $position = isset($metrics['position']) && is_numeric($metrics['position'])
                ? (float) $metrics['position']
                : null;
            $reason = $this->reasonTag($type, $metrics);

            if (! isset($byArticle[$articleId])) {
                if (count($byArticle) >= self::MAX_ITEMS) {
                    continue;
                }

                $articleMeta = $this->loadArticleMeta($siteId, $articleId, $normalized);
                if ($articleMeta === null || $articleMeta['url'] === '') {
                    // No publish URL → skip Social item (cannot share editor URL).
                    $pageArticleCache[$normalized] = null;
                    $unmappedPages++;

                    continue;
                }

                $byArticle[$articleId] = [
                    'article_id' => $articleId,
                    'title' => $articleMeta['title'],
                    'url' => $articleMeta['url'],
                    'path' => $articleMeta['path'],
                    'impressions' => $impressions,
                    'position' => $position,
                    'reason_tags' => $reason !== '' ? [$reason] : [],
                    'queries' => $query !== '' ? [$query] : [],
                    'signal_types' => [$type],
                ];

                continue;
            }

            $item = &$byArticle[$articleId];
            $item['impressions'] = max($item['impressions'], $impressions);
            if ($position !== null && ($item['position'] === null || $position < $item['position'])) {
                $item['position'] = $position;
            }
            if ($query !== '' && ! in_array($query, $item['queries'], true)) {
                $item['queries'][] = $query;
            }
            if ($reason !== '' && ! in_array($reason, $item['reason_tags'], true)) {
                $item['reason_tags'][] = $reason;
            }
            if (! in_array($type, $item['signal_types'], true)) {
                $item['signal_types'][] = $type;
            }
            unset($item);
        }

        // Preserve MCP priority: first-seen unique articles in signal order.
        $items = array_values($byArticle);
        if (count($items) > self::MAX_ITEMS) {
            $items = array_slice($items, 0, self::MAX_ITEMS);
        }

        return [
            'items' => $items,
            'unmapped_pages' => $unmappedPages,
            'period_key' => $periodKey,
            'excluded_no_page' => $excludedNoPage,
        ];
    }

    /**
     * Pure helper for tests — collapse signals with an injected page→article map.
     *
     * @param  list<array<string, mixed>>  $signals
     * @param  array<string, array{article_id: int, title: string, url: string, path?: string}>  $pageMap
     * @return array{items: list<SocialItem>, unmapped_pages: int, excluded_no_page: int}
     */
    public function buildFromSignals(array $signals, array $pageMap): array
    {
        $excludedNoPage = 0;
        $unmappedPages = 0;
        /** @var array<int, SocialItem> $byArticle */
        $byArticle = [];

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }
            $type = (string) ($signal['type'] ?? '');
            if (in_array($type, self::EXCLUDED_SIGNAL_TYPES, true)) {
                $excludedNoPage++;

                continue;
            }

            $page = $this->resolvePrimaryPage($signal);
            if ($page === '') {
                $excludedNoPage++;

                continue;
            }

            $normalized = $this->pageNormalizer->normalize($page)['normalized_url'];
            if ($normalized === '' || ! isset($pageMap[$normalized])) {
                $unmappedPages++;

                continue;
            }

            $mapped = $pageMap[$normalized];
            $articleId = (int) ($mapped['article_id'] ?? 0);
            $url = trim((string) ($mapped['url'] ?? ''));
            if ($articleId <= 0 || $url === '') {
                $unmappedPages++;

                continue;
            }

            $query = trim((string) ($signal['query'] ?? ''));
            $metrics = is_array($signal['metrics'] ?? null) ? $signal['metrics'] : [];
            $impressions = (int) ($metrics['impressions'] ?? 0);
            $position = isset($metrics['position']) && is_numeric($metrics['position'])
                ? (float) $metrics['position']
                : null;
            $reason = $this->reasonTag($type, $metrics);
            $path = (string) ($mapped['path'] ?? parse_url($url, PHP_URL_PATH) ?? '');

            if (! isset($byArticle[$articleId])) {
                if (count($byArticle) >= self::MAX_ITEMS) {
                    continue;
                }
                $byArticle[$articleId] = [
                    'article_id' => $articleId,
                    'title' => (string) ($mapped['title'] ?? ''),
                    'url' => $url,
                    'path' => $path,
                    'impressions' => $impressions,
                    'position' => $position,
                    'reason_tags' => $reason !== '' ? [$reason] : [],
                    'queries' => $query !== '' ? [$query] : [],
                    'signal_types' => [$type],
                ];

                continue;
            }

            $item = &$byArticle[$articleId];
            $item['impressions'] = max($item['impressions'], $impressions);
            if ($position !== null && ($item['position'] === null || $position < $item['position'])) {
                $item['position'] = $position;
            }
            if ($query !== '' && ! in_array($query, $item['queries'], true)) {
                $item['queries'][] = $query;
            }
            if ($reason !== '' && ! in_array($reason, $item['reason_tags'], true)) {
                $item['reason_tags'][] = $reason;
            }
            if (! in_array($type, $item['signal_types'], true)) {
                $item['signal_types'][] = $type;
            }
            unset($item);
        }

        return [
            'items' => array_values($byArticle),
            'unmapped_pages' => $unmappedPages,
            'excluded_no_page' => $excludedNoPage,
        ];
    }

    /**
     * @param  array<string, mixed>  $mcpPayload
     * @return list<array<string, mixed>>
     */
    private function extractSignals(array $mcpPayload): array
    {
        $context = is_array($mcpPayload['context'] ?? null) ? $mcpPayload['context'] : [];
        $signals = $context['planning_signals'] ?? [];

        return is_array($signals) ? array_values($signals) : [];
    }

    /**
     * @param  array<string, mixed>  $signal
     */
    private function resolvePrimaryPage(array $signal): string
    {
        $metrics = is_array($signal['metrics'] ?? null) ? $signal['metrics'] : [];

        foreach (['primary_page', 'normalized_page', 'page'] as $key) {
            $value = trim((string) ($metrics[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $pages = $metrics['pages'] ?? $metrics['competing_pages'] ?? null;
        if (is_array($pages)) {
            foreach ($pages as $row) {
                if (is_string($row) && trim($row) !== '') {
                    return trim($row);
                }
                if (is_array($row)) {
                    $candidate = trim((string) ($row['page'] ?? $row['normalized_page'] ?? ''));
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }
        }

        return '';
    }

    /**
     * @return array{article_id: int}|null
     */
    private function mapPageToArticle(int $siteId, string $normalizedPage, string $rawPage): ?array
    {
        $fromMapping = $this->articleIdFromPersistedMapping($siteId, $normalizedPage);
        if ($fromMapping !== null) {
            return ['article_id' => $fromMapping];
        }

        try {
            if ($this->urlResolver === null) {
                return null;
            }
            $article = $this->urlResolver->resolveArticleFromUrl($siteId, $rawPage)
                ?? $this->urlResolver->resolveArticleFromUrl($siteId, $normalizedPage);
            if ($article instanceof SeoArticle) {
                return ['article_id' => (int) $article->id];
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function articleIdFromPersistedMapping(int $siteId, string $normalizedPage): ?int
    {
        try {
            if (! Schema::connection('omi_seo_ai')->hasTable('seo_gsc_page_mappings')) {
                return null;
            }

            $mapping = SeoGscPageMapping::query()
                ->where('site_id', $siteId)
                ->where('normalized_page', $normalizedPage)
                ->whereNotNull('article_ref')
                ->where('article_ref', '!=', '')
                ->orderByDesc('id')
                ->first();

            if (! $mapping instanceof SeoGscPageMapping) {
                return null;
            }

            return $this->decodeArticleRef((string) $mapping->article_ref);
        } catch (Throwable) {
            return null;
        }
    }

    private function decodeArticleRef(string $ref): ?int
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        if (ctype_digit($ref)) {
            $id = (int) $ref;

            return $id > 0 ? $id : null;
        }

        try {
            $id = ContentProjectPublicRef::decodeArticle($ref);

            return $id > 0 ? $id : null;
        } catch (InvalidArgumentException) {
            // Legacy test refs like art_1 — not resolvable to real articles.
            return null;
        }
    }

    /**
     * @return array{title: string, url: string, path: string}|null
     */
    private function loadArticleMeta(int $siteId, int $articleId, string $fallbackNormalizedPage): ?array
    {
        try {
            $article = SeoArticle::query()
                ->where('site_id', $siteId)
                ->whereKey($articleId)
                ->first();
            if (! $article instanceof SeoArticle) {
                return null;
            }

            $url = trim((string) ($this->urlResolver?->resolveArticlePublicUrl($article) ?? ''));
            if ($url === '') {
                return null;
            }

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            if ($path === '' || $path === '/') {
                $path = (string) (parse_url($fallbackNormalizedPage, PHP_URL_PATH) ?? $path);
            }

            return [
                'title' => trim((string) ($article->title ?? '')),
                'url' => $url,
                'path' => $path,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function reasonTag(string $type, array $metrics): string
    {
        if (isset(self::REASON_TAGS[$type])) {
            return self::REASON_TAGS[$type];
        }

        if ((int) ($metrics['impressions'] ?? 0) >= 500) {
            return 'Impression cao';
        }

        return '';
    }
}
