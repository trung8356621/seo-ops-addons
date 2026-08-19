<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

final class McpDataQualityGuard
{
    /**
     * @param  array<string, mixed>  $distribution
     * @param  array<string, mixed>  $linking
     * @return list<string>
     */
    public function siteWarnings(int $articleTotal, array $distribution, array $linking): array
    {
        $warnings = [];
        foreach ((array) ($distribution['warnings'] ?? []) as $warning) {
            if (is_string($warning) && $warning !== '') {
                $warnings[] = $warning;
            }
        }
        foreach ((array) ($linking['warnings'] ?? []) as $warning) {
            if (is_string($warning) && $warning !== '') {
                $warnings[] = $warning;
            }
        }

        if ($articleTotal > 0 && ($distribution['available'] ?? false) === true) {
            $sum = 0;
            $known = 0;
            foreach (['posts', 'pages', 'categories', 'products', 'product_categories', 'other'] as $key) {
                if (! array_key_exists($key, $distribution) || $distribution[$key] === null) {
                    continue;
                }
                $sum += (int) $distribution[$key];
                $known++;
            }
            if ($known > 0 && $sum === 0) {
                $warnings[] = 'content distribution sums to zero while article_total='.$articleTotal;
            }
        }

        $linked = $linking['linked_articles'] ?? null;
        $eligible = $linking['eligible_articles'] ?? null;
        if (is_int($linked) && is_int($eligible) && $linked > $eligible) {
            $warnings[] = 'linked_articles exceeds eligible_articles';
        }

        return array_values(array_unique($warnings));
    }
}
