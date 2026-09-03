<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordLandscapeBuilder
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array{target_pages?: int, published?: int, planned?: int}>  $coverageByCluster
     * @return array<string, mixed>
     */
    public function build(array $rows, array $coverageByCluster = []): array
    {
        $raw = count($rows);
        $kinds = [
            'keyword_phrase' => 0,
            'query' => 0,
            'sentence' => 0,
            'descriptive_phrase' => 0,
            'brand_entity' => 0,
            'url_domain' => 0,
            'noise' => 0,
            'ambiguous' => 0,
        ];
        $usable = 0;
        $canonicals = [];
        $clusters = [];

        foreach ($rows as $row) {
            $kind = (string) ($row['phrase_kind'] ?? 'noise');
            if (isset($kinds[$kind])) {
                $kinds[$kind]++;
            }
            if ((bool) ($row['is_ambiguous'] ?? false)) {
                $kinds['ambiguous']++;
            }
            $isSeo = (bool) ($row['is_seo_keyword'] ?? false);
            if ($isSeo) {
                $usable++;
            }
            $cid = (string) ($row['canonical_keyword_id'] ?? $row['normalized_text'] ?? '');
            if ($isSeo && $cid !== '') {
                $canonicals[$cid] = true;
            }
            $clusterKey = (string) ($row['cluster_key'] ?? '');
            if ($clusterKey === '' || ! $isSeo) {
                continue;
            }
            $clusters[$clusterKey] ??= [
                'cluster_key' => $clusterKey,
                'primary' => (string) ($row['canonical_text'] ?? $row['normalized_text'] ?? ''),
                'usable_count' => 0,
                'queries' => [],
                'intents' => [],
                'variants' => [],
            ];
            $clusters[$clusterKey]['usable_count']++;
            $intent = (string) ($row['seo_intent'] ?? 'unknown');
            $clusters[$clusterKey]['intents'][$intent] = true;
            $phrase = (string) ($row['normalized_text'] ?? '');
            if ($kind === 'query') {
                $clusters[$clusterKey]['queries'][] = $phrase;
            } else {
                $clusters[$clusterKey]['variants'][] = $phrase;
            }
            $primary = (string) ($row['canonical_text'] ?? '');
            if ($primary !== '' && (mb_strlen($primary) < mb_strlen((string) $clusters[$clusterKey]['primary']) || $clusters[$clusterKey]['primary'] === '')) {
                $clusters[$clusterKey]['primary'] = $primary;
            }
        }

        $summaries = [];
        foreach ($clusters as $key => $cluster) {
            $cov = $coverageByCluster[$key] ?? [];
            $targetPages = (int) ($cov['target_pages'] ?? 0);
            $published = (int) ($cov['published'] ?? 0);
            $planned = (int) ($cov['planned'] ?? 0);
            $state = $this->coverageState((int) $cluster['usable_count'], $targetPages, $published, $planned);
            $intents = array_keys($cluster['intents']);
            $summaries[] = [
                'cluster' => $key,
                'primary' => $cluster['primary'],
                'usable_keyword_count' => $cluster['usable_count'],
                'queries' => array_slice(array_values(array_unique($cluster['queries'])), 0, 3),
                'representative_variants' => array_slice(array_values(array_unique($cluster['variants'])), 0, 5),
                'intent_coverage' => $intents,
                'intent_gaps' => $this->intentGaps($intents),
                'target_pages' => $targetPages,
                'published' => $published,
                'planned' => $planned,
                'coverage' => $state,
                'missing_directions' => $this->missingDirections($cluster['primary'], $cluster['variants']),
                'recommended_action' => $this->recommendedAction($state, $this->intentGaps($intents), $targetPages),
            ];
        }

        usort($summaries, static fn (array $a, array $b): int => $b['usable_keyword_count'] <=> $a['usable_keyword_count']);

        return [
            'raw_keywords' => $raw,
            'usable_seo_keywords' => $usable,
            'canonical_keywords' => count($canonicals),
            'cluster_count' => count($summaries),
            'classification' => $kinds,
            'clusters' => $summaries,
        ];
    }

    private function coverageState(int $usable, int $targetPages, int $published, int $planned): string
    {
        if ($usable <= 0 && $targetPages <= 0 && $published <= 0 && $planned <= 0) {
            return 'unknown';
        }
        if ($usable >= 20 && $targetPages >= 3) {
            return 'saturated';
        }
        if ($targetPages === 0 && $published === 0 && $planned === 0) {
            return $usable >= 1 ? 'missing' : 'unknown';
        }
        if ($targetPages <= 1 && $usable <= 5) {
            return 'weak';
        }
        if ($usable <= 3 && $published <= 1) {
            return 'weak';
        }

        return 'healthy';
    }

    /**
     * @param  list<string>  $intents
     * @return list<string>
     */
    private function intentGaps(array $intents): array
    {
        $wanted = ['informational', 'commercial', 'transactional'];
        $have = array_values(array_filter($intents, static fn (string $i): bool => $i !== 'unknown'));
        $gaps = [];
        foreach ($wanted as $intent) {
            if (! in_array($intent, $have, true)) {
                $gaps[] = $intent;
            }
        }

        return $gaps;
    }

    /**
     * @param  list<string>  $variants
     * @return list<string>
     */
    private function missingDirections(string $primary, array $variants): array
    {
        $blob = mb_strtolower($primary.' '.implode(' ', $variants));
        $lexicon = ['doanh nghiệp', 'sự kiện', 'trường học', 'báo giá', 'moq', 'in logo', 'thời gian sản xuất'];
        $missing = [];
        foreach ($lexicon as $dir) {
            if (! str_contains($blob, mb_strtolower($dir))) {
                $missing[] = $dir;
            }
        }

        return array_slice($missing, 0, 6);
    }

    /**
     * @param  list<string>  $intentGaps
     */
    private function recommendedAction(string $coverage, array $intentGaps, int $targetPages): string
    {
        return match ($coverage) {
            'saturated' => 'do_not_expand',
            'missing' => $targetPages === 0 ? 'create_content' : 'expand_keywords',
            'weak' => $intentGaps !== [] ? 'improve_targeting' : 'expand_keywords',
            'healthy' => $intentGaps !== [] ? 'improve_targeting' : 'rewrite_existing',
            default => 'expand_keywords',
        };
    }
}
