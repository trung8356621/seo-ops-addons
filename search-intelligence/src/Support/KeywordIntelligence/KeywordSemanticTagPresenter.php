<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;

/**
 * Semantic badges stripped — classification/DNA/cluster removed.
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
        unset($keyword, $dnaValues, $siteId);

        return [];
    }
}
