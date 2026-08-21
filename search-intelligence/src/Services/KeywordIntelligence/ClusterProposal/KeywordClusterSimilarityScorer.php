<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

final class KeywordClusterSimilarityScorer
{
    public function score(
        KeywordClusterTokenProfile $left,
        KeywordClusterTokenProfile $right,
        KeywordClusterCorpusStatistics $corpus,
    ): float {
        $weightedJaccard = $this->weightedJaccard($left->tokens, $right->tokens, $corpus);
        $bigramOverlap = $this->weightedBigramOverlap($left->bigrams, $right->bigrams, $corpus);
        $containment = $this->containmentBonus($left, $right, $corpus);
        $groupBonus = $this->groupBonus($left->groupKeys, $right->groupKeys);
        $charBonus = $this->lightCharacterSimilarity($left->significantPhrase, $right->significantPhrase);

        $score = (0.46 * $weightedJaccard)
            + (0.30 * $bigramOverlap)
            + $containment
            + $groupBonus
            + $charBonus;

        return min(1.0, max(0.0, round($score, 6)));
    }

    /**
     * @param  list<string>  $leftTokens
     * @param  list<string>  $rightTokens
     */
    private function weightedJaccard(array $leftTokens, array $rightTokens, KeywordClusterCorpusStatistics $corpus): float
    {
        $leftSet = array_values(array_unique($leftTokens));
        $rightSet = array_values(array_unique($rightTokens));
        if ($leftSet === [] || $rightSet === []) {
            return 0.0;
        }

        $intersectionWeight = 0.0;
        $union = [];
        foreach ($leftSet as $token) {
            $union[$token] = true;
        }
        foreach ($rightSet as $token) {
            $union[$token] = true;
        }

        foreach (array_keys($union) as $token) {
            $inLeft = in_array($token, $leftSet, true);
            $inRight = in_array($token, $rightSet, true);
            $weight = $corpus->weight((string) $token);
            if ($inLeft && $inRight) {
                $intersectionWeight += $weight;
            }
        }

        $unionWeight = 0.0;
        foreach (array_keys($union) as $token) {
            $unionWeight += $corpus->weight((string) $token);
        }

        if ($unionWeight <= 0.0) {
            return 0.0;
        }

        return $intersectionWeight / $unionWeight;
    }

    /**
     * @param  list<string>  $leftBigrams
     * @param  list<string>  $rightBigrams
     */
    private function weightedBigramOverlap(array $leftBigrams, array $rightBigrams, KeywordClusterCorpusStatistics $corpus): float
    {
        if ($leftBigrams === [] || $rightBigrams === []) {
            return 0.0;
        }

        $leftSet = array_fill_keys($leftBigrams, true);
        $rightSet = array_fill_keys($rightBigrams, true);
        $shared = array_intersect_key($leftSet, $rightSet);
        if ($shared === []) {
            return 0.0;
        }

        $sharedWeight = 0.0;
        foreach (array_keys($shared) as $bigram) {
            $parts = explode(' ', $bigram, 2);
            $tokenWeight = ($corpus->weight((string) $parts[0]) + $corpus->weight((string) ($parts[1] ?? $parts[0]))) / 2.0;
            $sharedWeight += $tokenWeight * 1.35;
        }

        $leftWeight = 0.0;
        foreach (array_keys($leftSet) as $bigram) {
            $parts = explode(' ', $bigram, 2);
            $leftWeight += ($corpus->weight((string) $parts[0]) + $corpus->weight((string) ($parts[1] ?? $parts[0]))) / 2.0;
        }
        $rightWeight = 0.0;
        foreach (array_keys($rightSet) as $bigram) {
            $parts = explode(' ', $bigram, 2);
            $rightWeight += ($corpus->weight((string) $parts[0]) + $corpus->weight((string) ($parts[1] ?? $parts[0]))) / 2.0;
        }

        $denominator = max($leftWeight, $rightWeight, 0.0001);

        return min(1.0, $sharedWeight / $denominator);
    }

    private function containmentBonus(
        KeywordClusterTokenProfile $left,
        KeywordClusterTokenProfile $right,
        KeywordClusterCorpusStatistics $corpus,
    ): float {
        [$shorter, $longer] = $this->orderedBySignificantLength($left, $right);
        $phrase = trim($shorter->significantPhrase);
        $container = trim($longer->foldedText);
        if ($phrase === '' || $container === '' || mb_strlen($phrase, 'UTF-8') < 3) {
            return 0.0;
        }

        $sigCount = count($shorter->significantTokens);
        $maxWeight = 0.0;
        foreach ($shorter->significantTokens as $token) {
            $maxWeight = max($maxWeight, $corpus->weight((string) $token));
        }
        if ($sigCount < 2 && $maxWeight < 1.25) {
            return 0.0;
        }

        $pattern = '/(?:^|\s)'.preg_quote($phrase, '/').'(?:\s|$)/u';
        if (! preg_match($pattern, $container)) {
            return 0.0;
        }

        $weightFactor = min(1.0, ($sigCount >= 2 ? 0.85 : 0.55) + ($maxWeight / 10.0));

        return min(0.24, 0.24 * $weightFactor);
    }

    /**
     * @param  list<string>  $leftGroups
     * @param  list<string>  $rightGroups
     */
    private function groupBonus(array $leftGroups, array $rightGroups): float
    {
        if ($leftGroups === [] || $rightGroups === []) {
            return 0.0;
        }

        return array_intersect($leftGroups, $rightGroups) !== [] ? 0.04 : 0.0;
    }

    private function lightCharacterSimilarity(string $left, string $right): float
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '' || $right === '') {
            return 0.0;
        }

        similar_text($left, $right, $percent);

        return min(0.08, ($percent / 100.0) * 0.08);
    }

    /**
     * @return array{0: KeywordClusterTokenProfile, 1: KeywordClusterTokenProfile}
     */
    private function orderedBySignificantLength(
        KeywordClusterTokenProfile $left,
        KeywordClusterTokenProfile $right,
    ): array {
        $leftLen = mb_strlen($left->significantPhrase, 'UTF-8');
        $rightLen = mb_strlen($right->significantPhrase, 'UTF-8');
        if ($leftLen <= $rightLen) {
            return [$left, $right];
        }

        return [$right, $left];
    }
}
