<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

final readonly class KeywordClusterTokenProfile
{
    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $bigrams
     * @param  list<string>  $significantTokens
     * @param  list<string>  $groupKeys
     */
    public function __construct(
        public int $keywordId,
        public string $phrase,
        public string $normalizedText,
        public string $foldedText,
        public string $seoIntent,
        public bool $isAmbiguous,
        public array $tokens,
        public array $bigrams,
        public array $significantTokens,
        public string $significantPhrase,
        public array $groupKeys,
    ) {}
}
