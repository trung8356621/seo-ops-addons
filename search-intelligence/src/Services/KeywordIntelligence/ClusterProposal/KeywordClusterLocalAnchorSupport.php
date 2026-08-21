<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\ClusterProposal;

final class KeywordClusterLocalAnchorSupport
{
    /**
     * @param  list<int>  $memberIds
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @return array<string, int>
     */
    public static function tokenFrequency(array $memberIds, array $profileMap): array
    {
        $frequency = [];
        foreach ($memberIds as $memberId) {
            $profile = $profileMap[$memberId] ?? null;
            if ($profile === null) {
                continue;
            }
            foreach (array_unique([...$profile->tokens, ...$profile->significantTokens]) as $token) {
                $frequency[(string) $token] = ($frequency[(string) $token] ?? 0) + 1;
            }
        }

        return $frequency;
    }

    /**
     * @param  list<int>  $memberIds
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     * @return array<string, list<int>>
     */
    public static function anchorMembers(array $memberIds, array $profileMap): array
    {
        /** @var array<string, list<int>> $anchorMembers */
        $anchorMembers = [];
        foreach ($memberIds as $memberId) {
            $profile = $profileMap[$memberId] ?? null;
            if ($profile === null) {
                continue;
            }
            foreach (array_unique(array_merge($profile->bigrams, $profile->significantTokens)) as $anchor) {
                $anchor = trim((string) $anchor);
                if ($anchor === '') {
                    continue;
                }
                $anchorMembers[$anchor][] = $memberId;
            }
        }

        foreach ($anchorMembers as $anchor => $ids) {
            $anchorMembers[$anchor] = array_values(array_unique($ids));
        }

        return $anchorMembers;
    }

    public static function isBroadFamilyToken(string $token, array $tokenFrequency, int $clusterSize, float $broadCoverageThreshold): bool
    {
        if ($clusterSize <= 0) {
            return false;
        }

        return (($tokenFrequency[$token] ?? 0) / $clusterSize) >= $broadCoverageThreshold;
    }

    /**
     * @param  list<int>  $leftIds
     * @param  list<int>  $rightIds
     * @param  array<int, KeywordClusterTokenProfile>  $profileMap
     */
    public static function sharedDiscriminativeAnchors(
        array $leftIds,
        array $rightIds,
        array $profileMap,
        float $broadCoverageThreshold,
    ): array {
        $leftAnchors = self::anchorMembers($leftIds, $profileMap);
        $rightAnchors = self::anchorMembers($rightIds, $profileMap);
        $leftFreq = self::tokenFrequency($leftIds, $profileMap);
        $rightFreq = self::tokenFrequency($rightIds, $profileMap);
        $leftSize = max(1, count($leftIds));
        $rightSize = max(1, count($rightIds));

        $shared = [];
        foreach ($leftAnchors as $anchor => $leftMembers) {
            if (! isset($rightAnchors[$anchor])) {
                continue;
            }

            $leftCoverage = count($leftMembers) / $leftSize;
            $rightCoverage = count($rightAnchors[$anchor]) / $rightSize;
            if ($leftCoverage < 0.06 && $rightCoverage < 0.06) {
                continue;
            }

            $tokens = str_contains($anchor, ' ') ? explode(' ', $anchor) : [$anchor];
            $isBroad = false;
            foreach ($tokens as $token) {
                if (
                    self::isBroadFamilyToken($token, $leftFreq, $leftSize, $broadCoverageThreshold)
                    && self::isBroadFamilyToken($token, $rightFreq, $rightSize, $broadCoverageThreshold)
                ) {
                    $isBroad = true;
                    break;
                }
            }

            if ($isBroad) {
                continue;
            }

            $shared[] = $anchor;
        }

        sort($shared, SORT_STRING);

        return $shared;
    }

    /**
     * @param  array<string, int>  $tokenFrequency
     */
    public static function broadSuppressionMultiplier(
        string $anchor,
        array $tokenFrequency,
        int $clusterSize,
        float $broadCoverageThreshold,
    ): float {
        if ($clusterSize <= 0) {
            return 1.0;
        }

        $tokens = str_contains($anchor, ' ') ? explode(' ', $anchor) : [$anchor];
        $maxCoverage = 0.0;
        foreach ($tokens as $token) {
            $maxCoverage = max($maxCoverage, ($tokenFrequency[$token] ?? 0) / $clusterSize);
        }

        if ($maxCoverage <= $broadCoverageThreshold) {
            return 1.0;
        }

        $excess = min(1.0, ($maxCoverage - $broadCoverageThreshold) / max(0.01, 1.0 - $broadCoverageThreshold));

        return max(0.05, 1.0 - ($excess * 0.92));
    }
}
