<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClusterQuery;

/**
 * Semantic badges: cluster core phrase + DNA extensions (UI only).
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
        $clusterKey = trim((string) ($keyword->seoClassification?->cluster_key ?? ''));
        $core = '';
        if ($clusterKey !== '') {
            $core = app(KeywordClusterQuery::class)->displayLabel($clusterKey, '', $siteId);
        }

        $tags = [];
        if ($core !== '') {
            $tags[] = [
                'type' => 'core',
                'label' => KeywordPhrasePresentation::present($core),
                'tone' => 'core',
            ];
        }

        $normalizedCore = mb_strtolower(trim($core), 'UTF-8');
        foreach ($dnaValues as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $normalized = mb_strtolower($value, 'UTF-8');
            if ($normalizedCore !== '' && $normalized === $normalizedCore) {
                continue;
            }
            $tags[] = [
                'type' => 'dna',
                'label' => KeywordPhrasePresentation::present($value),
                'tone' => 'dna-'.(abs(crc32($normalized)) % 5),
            ];
        }

        return $tags;
    }
}
