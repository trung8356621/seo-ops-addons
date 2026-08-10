<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

/**
 * Aggregate competitor domain appearances — lightweight summary.
 */
final class SerpCompetitorSummaryService
{
    public function __construct(
        private readonly SerpUrlNormalizationService $urlNormalizer,
        private readonly SerpOwnDomainDetector $ownDomainDetector,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<string>  $siteDomains
     * @return list<array{
     *   domain: string,
     *   appearances: int,
     *   best_position: ?int,
     *   avg_position: ?float,
     *   urls: list<string>,
     *   is_own: bool
     * }>
     */
    public function summarize(array $results, array $siteDomains = [], ?array $config = null): array
    {
        $topN = (int) ($config['top_n'] ?? 10);
        $byDomain = [];

        foreach (array_slice($results, 0, $topN) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $url = (string) ($row['url'] ?? $row['link'] ?? '');
            if ($url === '') {
                continue;
            }

            $normalized = $this->urlNormalizer->normalize($url);
            $domain = $normalized['normalized_domain'];
            if ($domain === '') {
                continue;
            }

            $position = (int) ($row['position'] ?? ($index + 1));
            if (! isset($byDomain[$domain])) {
                $byDomain[$domain] = [
                    'domain' => $domain,
                    'appearances' => 0,
                    'best_position' => null,
                    'positions' => [],
                    'urls' => [],
                    'is_own' => $this->ownDomainDetector->isOwnDomain($domain, $siteDomains),
                ];
            }

            $byDomain[$domain]['appearances']++;
            $byDomain[$domain]['positions'][] = $position;
            $byDomain[$domain]['best_position'] = $byDomain[$domain]['best_position'] === null
                ? $position
                : min($byDomain[$domain]['best_position'], $position);

            $normalizedUrl = (string) ($row['normalized_url'] ?? $normalized['normalized_url']);
            if ($normalizedUrl !== '' && ! in_array($normalizedUrl, $byDomain[$domain]['urls'], true)) {
                $byDomain[$domain]['urls'][] = $normalizedUrl;
            }
        }

        $summary = [];
        foreach ($byDomain as $entry) {
            $positions = $entry['positions'];
            $summary[] = [
                'domain' => $entry['domain'],
                'appearances' => $entry['appearances'],
                'best_position' => $entry['best_position'],
                'avg_position' => $positions !== [] ? round(array_sum($positions) / count($positions), 2) : null,
                'urls' => array_slice($entry['urls'], 0, 5),
                'is_own' => $entry['is_own'],
            ];
        }

        usort($summary, static fn (array $a, array $b): int => ($a['best_position'] ?? 999) <=> ($b['best_position'] ?? 999));

        return $summary;
    }
}
