<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicClusterMeta;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

/**
 * Lightweight DNA quality diagnostics (QA counters + sample suspicious rows).
 */
final class KeywordDnaDiagnosticsService
{
    /** @var list<string> */
    private const GLUE_LIKE = [
        'tai', 'o', 'cho', 'la', 'cua', 'va', 'voi', 'den', 'tu', 'trong', 'theo',
        've', 'hoac', 'mot', 'cac', 'xuong', 'may',
    ];

    public function __construct(
        private readonly KeywordNormalizer $normalizer,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_keyword_dna');
    }

    /**
     * @return array{
     *     dna_total: int,
     *     dna_unique: int,
     *     dna_without_articles: int,
     *     dna_suspicious: int,
     *     dna_duplicate_normalized: int,
     *     suspicious_samples: list<array{keyword_id: int, value: string, normalized_value: string, reason: string}>
     * }
     */
    public function report(int $siteId, int $sampleLimit = 25): array
    {
        if ($siteId <= 0 || ! $this->tablesReady()) {
            return [
                'dna_total' => 0,
                'dna_unique' => 0,
                'dna_without_articles' => 0,
                'dna_suspicious' => 0,
                'dna_duplicate_normalized' => 0,
                'suspicious_samples' => [],
            ];
        }

        $total = (int) SeoKeywordDna::query()->where('site_id', $siteId)->count();
        $unique = (int) SeoKeywordDna::query()
            ->where('site_id', $siteId)
            ->distinct()
            ->count('normalized_value');

        $withoutArticles = 0;
        if (Schema::connection('omi_seo_ai')->hasTable('seo_link_maps')) {
            $withoutArticles = (int) DB::connection('omi_seo_ai')->table('seo_keyword_dna as d')
                ->leftJoin('seo_link_maps as lm', function ($join): void {
                    $join->on('lm.keyword_id', '=', 'd.keyword_id')
                        ->whereNotNull('lm.target_article_id');
                })
                ->where('d.site_id', $siteId)
                ->whereNull('lm.id')
                ->distinct()
                ->count('d.id');
        }

        $duplicateNormalized = (int) DB::connection('omi_seo_ai')->table('seo_keyword_dna')
            ->where('site_id', $siteId)
            ->selectRaw('keyword_id, normalized_value, COUNT(*) as c')
            ->groupBy('keyword_id', 'normalized_value')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $canonicalByKey = [];
        if (Schema::connection('omi_seo_ai')->hasTable('seo_topic_cluster_meta')) {
            $canonicalByKey = SeoTopicClusterMeta::query()
                ->where('site_id', $siteId)
                ->pluck('normalized_canonical', 'cluster_key')
                ->map(static fn ($v): string => (string) $v)
                ->all();
        }

        $rows = SeoKeywordDna::query()
            ->where('site_id', $siteId)
            ->orderBy('id')
            ->limit(5000)
            ->get(['keyword_id', 'cluster_key', 'value', 'normalized_value']);

        $suspicious = 0;
        $samples = [];
        foreach ($rows as $row) {
            $reason = $this->suspiciousReason(
                (string) $row->value,
                (string) $row->normalized_value,
                (string) ($canonicalByKey[(string) $row->cluster_key] ?? ''),
            );
            if ($reason === null) {
                continue;
            }
            $suspicious++;
            if (count($samples) < $sampleLimit) {
                $samples[] = [
                    'keyword_id' => (int) $row->keyword_id,
                    'value' => (string) $row->value,
                    'normalized_value' => (string) $row->normalized_value,
                    'reason' => $reason,
                ];
            }
        }

        return [
            'dna_total' => $total,
            'dna_unique' => $unique,
            'dna_without_articles' => $withoutArticles,
            'dna_suspicious' => $suspicious,
            'dna_duplicate_normalized' => $duplicateNormalized,
            'suspicious_samples' => $samples,
        ];
    }

    private function suspiciousReason(string $value, string $normalized, string $clusterNormalized): ?string
    {
        $normalized = trim($normalized);
        if ($normalized === '' || mb_strlen($normalized) < 2) {
            return 'too_short';
        }
        if (in_array($normalized, self::GLUE_LIKE, true)) {
            return 'glue_like';
        }
        if ($clusterNormalized !== '' && $normalized === $clusterNormalized) {
            return 'equals_cluster';
        }
        if (str_starts_with($normalized, 'tai ') || str_starts_with($normalized, 'o ')) {
            return 'location_wrapper';
        }
        if (str_contains($normalized, 'xuong may') || $normalized === 'xuong' || $normalized === 'may') {
            return 'cluster_echo';
        }

        return null;
    }
}
