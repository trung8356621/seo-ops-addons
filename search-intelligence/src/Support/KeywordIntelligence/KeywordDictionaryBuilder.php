<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordDictionaryBuilder
{
    public function __construct(
        private readonly KeywordClusterKey $clusterKeyMaker = new KeywordClusterKey(),
    ) {}

    /**
     * Compact WP dictionary: canonicals + representative variants/queries/anchors only.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{version: string, hash: string, clusters: list<array<string, mixed>>}
     */
    public function build(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $kind = (string) ($row['phrase_kind'] ?? '');
            if (in_array($kind, ['sentence', 'noise', 'url_domain', 'descriptive_phrase'], true)) {
                continue;
            }
            $usable = $row['is_seo_keyword'] ?? in_array($kind, ['keyword_phrase', 'query', 'brand_entity'], true);
            if (! $usable) {
                continue;
            }
            $clusterKey = (string) ($row['cluster_key'] ?? $this->clusterKey((string) ($row['normalized_text'] ?? '')));
            if ($clusterKey === '') {
                continue;
            }
            $groups[$clusterKey] ??= [
                'cluster_key' => $clusterKey,
                'primary' => '',
                'intent' => (string) ($row['seo_intent'] ?? 'unknown'),
                'variants' => [],
                'queries' => [],
                'anchor_candidates' => [],
            ];
            $phrase = (string) ($row['canonical_text'] ?? $row['normalized_text'] ?? $row['raw_text'] ?? '');
            if ($phrase === '') {
                continue;
            }
            if ($kind === 'query') {
                $groups[$clusterKey]['queries'][] = $phrase;
            } elseif (in_array($kind, ['keyword_phrase', 'brand_entity'], true)) {
                $groups[$clusterKey]['variants'][] = $phrase;
                if ((bool) ($row['is_anchor_candidate'] ?? false)) {
                    $groups[$clusterKey]['anchor_candidates'][] = $phrase;
                }
                $primary = (string) ($row['canonical_text'] ?? '');
                if ($primary !== '' && $groups[$clusterKey]['primary'] === '') {
                    $groups[$clusterKey]['primary'] = $primary;
                } elseif ($groups[$clusterKey]['primary'] === '' || mb_strlen($phrase) < mb_strlen((string) $groups[$clusterKey]['primary'])) {
                    $groups[$clusterKey]['primary'] = $phrase;
                }
            }
        }

        $clusters = [];
        foreach ($groups as $cluster) {
            $cluster['variants'] = array_slice(array_values(array_unique($cluster['variants'])), 0, 5);
            $cluster['queries'] = array_slice(array_values(array_unique($cluster['queries'])), 0, 3);
            $cluster['anchor_candidates'] = array_slice(array_values(array_unique($cluster['anchor_candidates'])), 0, 5);
            if ($cluster['primary'] === '' && $cluster['variants'] !== []) {
                $cluster['primary'] = $cluster['variants'][0];
            }
            if ($cluster['primary'] === '') {
                continue;
            }
            $clusters[] = $cluster;
        }

        $payload = ['clusters' => $clusters];
        $hash = hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'version' => substr($hash, 0, 12),
            'hash' => $hash,
            'clusters' => $clusters,
        ];
    }

    public function clusterKey(string $normalized): string
    {
        return $this->clusterKeyMaker->make($normalized);
    }
}
