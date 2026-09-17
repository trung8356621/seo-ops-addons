<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;

/**
 * Pure presentation helper: map explicit DNA value strings to semantic badges.
 *
 * Does not query classification, retired cluster identity, or retired DNA services.
 *
 * @return list<array{type: string, label: string, tone: string}>
 */
final class KeywordSemanticTagPresenter
{
    /**
     * @param  list<string>  $dnaValues
     * @return list<array{type: string, label: string, tone: string}>
     */
    public function forKeyword(Keyword $keyword, array $dnaValues = [], ?int $siteId = null): array
    {
        unset($keyword, $siteId);

        return $this->fromDnaValues($dnaValues);
    }

    /**
     * @param  list<string>  $dnaValues
     * @return list<array{type: string, label: string, tone: string}>
     */
    public function fromDnaValues(array $dnaValues): array
    {
        $tags = [];
        $seen = [];

        foreach ($dnaValues as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $normalized = mb_strtolower($value, 'UTF-8');
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $tags[] = [
                'type' => 'dna',
                'label' => KeywordPhrasePresentation::present($value),
                'tone' => 'dna-'.(abs(crc32($normalized)) % 5),
            ];
        }

        return $tags;
    }
}
