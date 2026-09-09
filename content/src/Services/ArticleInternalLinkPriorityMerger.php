<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\Seo\Support\SeoSuggestionUrlNormalizer;

/**
 * Staged Internal Link merge — source stage always beats global score.
 *
 * Final order: source_priority ASC, match_score DESC.
 * Never sort the pooled list by score alone.
 */
final class ArticleInternalLinkPriorityMerger
{
    public const STAGE_PRODUCT_CAT = 'product_cat';

    public const STAGE_TOPIC = 'topic';

    public const STAGE_KEYWORD_NON_TOPIC = 'keyword_non_topic';

    public const STAGE_GENERIC = 'generic';

    /** @var array<string, int> */
    public const STAGE_PRIORITY = [
        self::STAGE_PRODUCT_CAT => 1,
        self::STAGE_TOPIC => 2,
        self::STAGE_KEYWORD_NON_TOPIC => 3,
        self::STAGE_GENERIC => 4,
    ];

    /**
     * @param  array{
     *     product_cat?: list<array<string, mixed>>,
     *     topic?: list<array<string, mixed>>,
     *     keyword_non_topic?: list<array<string, mixed>>,
     *     generic?: list<array<string, mixed>>
     * }  $byStage
     * @param  list<string>  $alreadyLinkedNormalizedUrls
     * @param  list<string>  $alreadyLinkedLabels
     * @return list<array<string, mixed>>
     */
    public function merge(
        array $byStage,
        array $alreadyLinkedNormalizedUrls = [],
        array $alreadyLinkedLabels = [],
    ): array {
        $seenUrls = [];
        foreach ($alreadyLinkedNormalizedUrls as $url) {
            $key = $this->urlDedupeKey((string) $url);
            if ($key !== '') {
                $seenUrls[$key] = true;
            }
        }

        $seenLabels = [];
        foreach ($alreadyLinkedLabels as $label) {
            $norm = KeywordPhraseMatcher::normalize((string) $label);
            if ($norm !== '') {
                $seenLabels[$norm] = true;
            }
        }

        $out = [];

        foreach (self::STAGE_PRIORITY as $stage => $priority) {
            $rows = is_array($byStage[$stage] ?? null) ? $byStage[$stage] : [];
            usort(
                $rows,
                static fn (array $a, array $b): int => ((int) ($b['score'] ?? 0)) <=> ((int) ($a['score'] ?? 0)),
            );

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $href = trim((string) ($row['href'] ?? $row['target_url'] ?? ''));
                $urlKey = $this->urlDedupeKey($href);
                if ($urlKey !== '' && isset($seenUrls[$urlKey])) {
                    continue;
                }

                $anchorKey = KeywordPhraseMatcher::normalize((string) ($row['text'] ?? ''));
                if ($anchorKey !== '' && isset($seenLabels[$anchorKey])) {
                    continue;
                }

                $item = $row;
                $item['source_stage'] = $stage;
                $item['source_priority'] = $priority;
                $item['candidate_source'] = (string) ($item['candidate_source'] ?? $stage);
                if (! isset($item['provenance']) || ! is_array($item['provenance'])) {
                    $item['provenance'] = [];
                }
                $item['provenance']['source_stage'] = $stage;
                $item['provenance']['source_priority'] = $priority;

                $out[] = $item;

                if ($urlKey !== '') {
                    $seenUrls[$urlKey] = true;
                }
                if ($anchorKey !== '') {
                    $seenLabels[$anchorKey] = true;
                }
            }
        }

        return $out;
    }

    public static function priorityFor(string $stage): int
    {
        return self::STAGE_PRIORITY[$stage] ?? 99;
    }

    /**
     * Accept full URLs or already-normalized host/path keys from the pipeline.
     * Re-running normalize() on host/path keys yields '' (no scheme → no host).
     */
    private function urlDedupeKey(string $url): string
    {
        $raw = trim($url);
        if ($raw === '') {
            return '';
        }

        $norm = SeoSuggestionUrlNormalizer::normalize($raw);
        if ($norm !== '') {
            return $norm;
        }

        // Already-normalized keys such as "example.com/path".
        return strtolower(rtrim($raw, '/'));
    }
}
