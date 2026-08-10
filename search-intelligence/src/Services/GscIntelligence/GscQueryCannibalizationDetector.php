<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscCannibalizationType;

/**
 * Detect query cannibalization — suggestions only, không auto consolidate.
 */
final class GscQueryCannibalizationDetector
{
    public const ALGORITHM_VERSION = '1.0.0';

    /**
     * @param  list<array<string, mixed>>  $rows  normalized_query, normalized_page, clicks, impressions, date?
     * @return list<array<string, mixed>>
     */
    public function detect(string $normalizedQuery, array $rows): array
    {
        $minPages = $this->configInt('cannibalization.min_competing_pages', 2);
        $minImpressionsPerPage = $this->configInt('cannibalization.min_impressions_per_page', 10);

        $filtered = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) ($row['normalized_query'] ?? '') === $normalizedQuery,
        ));

        if ($filtered === []) {
            return [];
        }

        /** @var array<string, array{page: string, clicks: int, impressions: int, dates: list<string>}> */
        $byPage = [];
        foreach ($filtered as $row) {
            $page = (string) ($row['normalized_page'] ?? $row['page'] ?? '');
            if ($page === '') {
                continue;
            }

            if (! isset($byPage[$page])) {
                $byPage[$page] = ['page' => $page, 'clicks' => 0, 'impressions' => 0, 'dates' => []];
            }

            $byPage[$page]['clicks'] += (int) ($row['clicks'] ?? 0);
            $byPage[$page]['impressions'] += (int) ($row['impressions'] ?? 0);
            if (isset($row['date'])) {
                $byPage[$page]['dates'][] = (string) $row['date'];
            }
        }

        $competing = array_values(array_filter(
            $byPage,
            static fn (array $p): bool => $p['impressions'] >= $minImpressionsPerPage,
        ));

        if (count($competing) < $minPages) {
            return [];
        }

        $issues = [];
        $issues[] = $this->issue(
            GscCannibalizationType::CompetingPages,
            $normalizedQuery,
            $competing,
            ['page_count' => count($competing)],
        );

        $topPage = $this->topPageByPeriod($competing);
        if ($topPage !== null && $this->hasAlternatingLeadership($competing)) {
            $issues[] = $this->issue(
                GscCannibalizationType::AlternatingPage,
                $normalizedQuery,
                $competing,
                ['alternating' => true, 'sample_top_pages' => $topPage],
            );
        }

        if ($this->isExpectedMultiPage($normalizedQuery, count($competing))) {
            $issues[] = $this->issue(
                GscCannibalizationType::ExpectedMultiPage,
                $normalizedQuery,
                $competing,
                ['expected_multi_page' => true],
            );
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    private function issue(GscCannibalizationType $type, string $query, array $pages, array $metadata): array
    {
        return [
            'type' => $type->value,
            'normalized_query' => $query,
            'competing_pages' => array_map(static fn (array $p): array => [
                'page' => $p['page'],
                'clicks' => $p['clicks'],
                'impressions' => $p['impressions'],
            ], $pages),
            'metadata' => $metadata,
            'auto_consolidate' => false,
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
    }

    /**
     * @param  list<array{page: string, clicks: int, impressions: int, dates: list<string>}>  $pages
     * @return list<string>|null
     */
    private function topPageByPeriod(array $pages): ?array
    {
        usort($pages, static fn (array $a, array $b): int => $b['clicks'] <=> $a['clicks']);

        return array_slice(array_map(static fn (array $p): string => $p['page'], $pages), 0, 2) ?: null;
    }

    /**
     * @param  list<array{page: string, clicks: int, impressions: int, dates: list<string>}>  $pages
     */
    private function hasAlternatingLeadership(array $pages): bool
    {
        if (count($pages) < 2) {
            return false;
        }

        usort($pages, static fn (array $a, array $b): int => $b['clicks'] <=> $a['clicks']);
        $top = $pages[0]['clicks'];
        $second = $pages[1]['clicks'];

        return $second > 0 && ($top - $second) / max(1, $top) < 0.15;
    }

    private function isExpectedMultiPage(string $normalizedQuery, int $pageCount): bool
    {
        // Navigational / homepage-style queries may legitimately surface multiple URLs.
        $navigationalHints = ['trang chủ', 'homepage', 'login', 'dang nhap', 'đăng nhập'];
        foreach ($navigationalHints as $hint) {
            if (str_contains($normalizedQuery, $hint)) {
                return $pageCount >= 2;
            }
        }

        return false;
    }

    private function configInt(string $key, int $default): int
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (int) config('seo-content-ai.gsc_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
