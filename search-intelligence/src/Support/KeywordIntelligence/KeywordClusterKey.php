<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

final class KeywordClusterKey
{
    /**
     * Topical cluster key (v2). Intent is stored separately and must not be merged away.
     * Leading intent tokens are stripped so "giá túi canvas" shares family with "túi canvas".
     */
    public function make(string $normalized, string $folded = ''): string
    {
        $source = mb_strtolower(trim($folded !== '' ? $folded : $normalized), 'UTF-8');
        $tokens = array_values(array_filter(preg_split('/\s+/u', $source) ?: []));
        $stop = [
            'gia', 'gia', 'tai', 'o', 'cua', 'va', 'cho', 'cac', 'nhung', 'mot', 'cac',
            'mua', 'gia', 'dat', 'order', 'buy', 'cach', 'huong', 'dan', 'la',
        ];
        $intentLead = ['mua', 'gia', 'dat', 'order', 'buy', 'cach', 'huong', 'dan'];
        while ($tokens !== [] && in_array($tokens[0], $intentLead, true)) {
            array_shift($tokens);
        }
        $tokens = array_values(array_filter(
            $tokens,
            static fn (string $t): bool => $t !== '' && ! in_array($t, ['tai', 'o', 'cua', 'va', 'cho'], true),
        ));
        if ($tokens === []) {
            $tokens = array_values(array_filter(preg_split('/\s+/u', $source) ?: []));
        }
        $head = array_slice($tokens, 0, min(3, count($tokens)));

        return implode('_', $head);
    }
}
