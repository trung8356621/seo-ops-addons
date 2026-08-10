<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Illuminate\Support\Collection;

/**
 * Chia nhỏ tập keyword thành bucket theo intent + main token + modifier (location/commercial)
 * để các thuật toán near-duplicate/cluster tránh so sánh O(n²) trên toàn bộ workspace.
 */
final class KeywordCandidateBucketer
{
    private const DEFAULT_MAX_BUCKET_SIZE = 200;

    /**
     * @var list<string>
     */
    private const LOCATION_MODIFIERS = [
        'tphcm', 'tp hcm',
        "h\u{1ED3} ch\u{00ED} minh", // hồ chí minh
        "h\u{00E0} n\u{1ED9}i", // hà nội
        'ha noi',
        "\u{0111}\u{00E0} n\u{1EB5}ng", // đà nẵng
        'da nang',
        "c\u{1EA7}n th\u{01A1}", // cần thơ
        'can tho',
        "g\u{1EA7}n \u{0111}\u{00E2}y", // gần đây
        'near me',
        'quan ', 'huyen ',
    ];

    /**
     * @var list<string>
     */
    private const COMMERCIAL_MODIFIERS = [
        "d\u{1ECB}ch v\u{1EE5}", // dịch vụ
        'dich vu',
        "gi\u{00E1}", // giá
        'gia',
        'mua', 'best', 'top', 'review',
        "so s\u{00E1}nh", // so sánh
        'so sanh',
        "\u{0111}\u{00E1}nh gi\u{00E1}", // đánh giá
        'danh gia',
    ];

    /**
     * @param  Collection<int, SeoKiKeyword>|list<SeoKiKeyword>  $keywords
     * @return array{
     *   buckets: array<string, list<SeoKiKeyword>>,
     *   warnings: list<string>,
     *   truncated_bucket_keys: list<string>
     * }
     */
    public function bucket(Collection|array $keywords, string $strategy = 'default'): array
    {
        /** @var array<string, list<SeoKiKeyword>> $buckets */
        $buckets = [];

        foreach ($keywords as $keyword) {
            if (! $keyword instanceof SeoKiKeyword) {
                continue;
            }

            $key = $this->bucketKey($keyword, $strategy);
            $buckets[$key][] = $keyword;
        }

        $maxBucketSize = $this->maxBucketSizeConfig();
        $truncated = [];

        if ($maxBucketSize > 0) {
            foreach ($buckets as $key => $items) {
                if (count($items) > $maxBucketSize) {
                    $buckets[$key] = array_slice($items, 0, $maxBucketSize);
                    $truncated[] = $key;
                }
            }
        }

        $warnings = $truncated !== [] ? [KeywordIntelligenceActionCodes::BUCKET_TRUNCATED] : [];

        return [
            'buckets' => $buckets,
            'warnings' => $warnings,
            'truncated_bucket_keys' => $truncated,
        ];
    }

    private function bucketKey(SeoKiKeyword $keyword, string $strategy): string
    {
        $intent = $keyword->search_intent instanceof \BackedEnum
            ? $keyword->search_intent->value
            : (string) ($keyword->search_intent ?? 'unknown');

        $normalized = (string) $keyword->normalized_keyword;
        $tokens = preg_split('/\s+/u', trim($normalized)) ?: [];
        $mainToken = $tokens[0] ?? $normalized;

        $hasLocation = $this->containsAny($normalized, self::LOCATION_MODIFIERS);
        $hasCommercial = $this->containsAny($normalized, self::COMMERCIAL_MODIFIERS);

        if ($strategy === 'strict') {
            return implode('|', [$intent, $normalized]);
        }

        return implode('|', [
            $intent,
            $mainToken,
            $hasLocation ? 'loc' : 'noloc',
            $hasCommercial ? 'com' : 'nocom',
        ]);
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function maxBucketSizeConfig(): int
    {
        if (! function_exists('config')) {
            return self::DEFAULT_MAX_BUCKET_SIZE;
        }

        try {
            return (int) config('seo-content-ai.keyword_intelligence.near_duplicate.max_bucket_size', self::DEFAULT_MAX_BUCKET_SIZE);
        } catch (\Throwable) {
            return self::DEFAULT_MAX_BUCKET_SIZE;
        }
    }
}
