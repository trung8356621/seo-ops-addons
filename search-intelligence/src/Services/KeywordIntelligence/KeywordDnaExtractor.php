<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical\CanonicalClusterPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

/**
 * Deterministic keyword DNA extraction (residual modifiers after cluster core).
 *
 * DNA = meaningful semantic modifier — not raw token difference / glue / cluster echo.
 */
final class KeywordDnaExtractor
{
    /** @var list<string> */
    private const GLUE = [
        'tai', 'o', 'cho', 'la', 'cua', 'va', 'voi', 'den', 'tu', 'trong', 'theo',
        'mot', 'cac', 'nhung', 've', 'hoac', 'va', 'and', 'or', 'of', 'for', 'at', 'in',
    ];

    /** Location / discourse wrappers stripped from residual DNA display. */
    private const LOCATION_WRAPPERS = ['tai', 'o', 'tai thanh pho', 'o thanh pho'];

    /** @var list<string> */
    private const KNOWN_FACETS = [
        'canvas' => 'material',
        'vai' => 'material',
        'da' => 'material',
        'nhua' => 'material',
        'hoc sinh' => 'audience',
        'tre em' => 'audience',
        'nam' => 'audience',
        'nu' => 'audience',
        'the thao' => 'use_case',
        'thoi trang' => 'style',
        'gia re' => 'feature',
        'gia si' => 'feature',
    ];

    public function __construct(
        private readonly KeywordNormalizer $normalizer,
        private readonly CanonicalClusterPhraseResolver $phraseResolver,
    ) {}

    /**
     * @return list<array{value: string, normalized_value: string, facet_type: ?string, confidence: string}>
     */
    public function extract(string $keywordPhrase, string $clusterCanonical): array
    {
        $keywordPhrase = trim($keywordPhrase);
        $clusterCanonical = trim($clusterCanonical);
        if ($keywordPhrase === '' || $clusterCanonical === '') {
            return [];
        }

        $kwNorm = $this->normalizer->normalize($keywordPhrase);
        $clNorm = $this->normalizer->normalize($clusterCanonical);

        if ($kwNorm['folded_text'] === $clNorm['folded_text']) {
            return [];
        }

        $kwTokens = $this->phraseResolver->significantTokens($keywordPhrase);
        $clTokens = $this->phraseResolver->significantTokens($clusterCanonical);

        if ($kwTokens === [] || $clTokens === []) {
            return [];
        }

        if ($this->containsSubsequence($kwTokens, $clTokens)) {
            $residual = $this->residualTokens($kwTokens, $clTokens);
        } else {
            $residual = $this->semanticResidual($keywordPhrase, $clusterCanonical, $kwTokens, $clTokens);
        }

        $residual = $this->removeClusterSpans($residual, $clTokens);
        $residual = array_values(array_filter(
            $residual,
            static fn (string $t): bool => ! in_array($t, self::GLUE, true) && mb_strlen($t) >= 2,
        ));

        return $this->composeDnaValues($keywordPhrase, $residual, $clNorm['folded_text'], $clTokens);
    }

    /**
     * @param  list<string>  $residual
     * @param  list<string>  $clusterTokens
     * @return list<array{value: string, normalized_value: string, facet_type: ?string, confidence: string}>
     */
    private function composeDnaValues(
        string $originalPhrase,
        array $residual,
        string $clusterFolded,
        array $clusterTokens,
    ): array {
        if ($residual === []) {
            return [];
        }

        $values = [];
        $used = [];
        $joined = implode(' ', $residual);

        foreach (self::KNOWN_FACETS as $pattern => $facet) {
            if (! str_contains($joined, $pattern) || isset($used[$pattern])) {
                continue;
            }
            $display = $this->displayFragment($originalPhrase, $pattern);
            $normalized = $this->normalizeDnaValue($pattern);
            if (! $this->isAcceptableDna($normalized, $display, $clusterFolded, $clusterTokens)) {
                $residual = $this->removePatternTokens($residual, $pattern);

                continue;
            }
            $values[] = [
                'value' => $display !== '' ? $display : $pattern,
                'normalized_value' => $normalized,
                'facet_type' => $facet,
                'confidence' => 'high',
            ];
            $used[$normalized] = true;
            $residual = $this->removePatternTokens($residual, $pattern);
        }

        $chunks = $this->groupRemainingTokens($originalPhrase, $residual);
        foreach ($chunks as $chunk) {
            [$display, $normalized] = $this->canonicalizeDnaChunk($originalPhrase, $chunk);
            if ($normalized === '' || isset($used[$normalized])) {
                continue;
            }
            if (! $this->isAcceptableDna($normalized, $display, $clusterFolded, $clusterTokens)) {
                continue;
            }
            $used[$normalized] = true;
            $values[] = [
                'value' => $display,
                'normalized_value' => $normalized,
                'facet_type' => $this->guessFacet($normalized),
                'confidence' => 'medium',
            ];
        }

        return $values;
    }

    /**
     * @return array{0: string, 1: string} display, normalized
     */
    private function canonicalizeDnaChunk(string $originalPhrase, string $chunk): array
    {
        $folded = $this->normalizer->fold(mb_strtolower(trim($chunk), 'UTF-8'));
        $folded = $this->stripLocationWrapper($folded);
        if ($folded === '') {
            return ['', ''];
        }

        $display = $this->displayFragment($originalPhrase, $folded);
        if ($display === '') {
            $display = $folded;
        }

        return [$display, $this->normalizeDnaValue($folded)];
    }

    private function stripLocationWrapper(string $folded): string
    {
        $working = trim($folded);
        foreach (self::LOCATION_WRAPPERS as $wrapper) {
            if ($working === $wrapper) {
                return '';
            }
            if (str_starts_with($working, $wrapper.' ')) {
                $working = trim(substr($working, strlen($wrapper)));
            }
        }

        return $working;
    }

    private function normalizeDnaValue(string $foldedOrDisplay): string
    {
        return trim(preg_replace('/\s+/u', ' ', $this->normalizer->fold($foldedOrDisplay)) ?? '');
    }

    /**
     * @param  list<string>  $clusterTokens
     */
    private function isAcceptableDna(
        string $normalized,
        string $display,
        string $clusterFolded,
        array $clusterTokens,
    ): bool {
        if ($normalized === '' || mb_strlen($normalized) < 2) {
            return false;
        }
        if (in_array($normalized, self::GLUE, true)) {
            return false;
        }
        if ($normalized === $clusterFolded) {
            return false;
        }
        // DNA must not be only a contiguous span of the cluster core.
        $dnaTokens = array_values(array_filter(explode(' ', $normalized)));
        if ($dnaTokens !== [] && $this->containsSubsequence($clusterTokens, $dnaTokens)) {
            return false;
        }
        // Reject pure cluster-token bags.
        $onlyCluster = true;
        foreach ($dnaTokens as $token) {
            if (! in_array($token, $clusterTokens, true)) {
                $onlyCluster = false;
                break;
            }
        }
        if ($onlyCluster) {
            return false;
        }

        $displayTrim = trim($display);
        if ($displayTrim !== '' && mb_strlen($displayTrim) < 2) {
            return false;
        }

        return true;
    }

    /**
     * Remove contiguous cluster n-grams (and individual cluster tokens) from residual.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $clusterTokens
     * @return list<string>
     */
    private function removeClusterSpans(array $tokens, array $clusterTokens): array
    {
        if ($tokens === [] || $clusterTokens === []) {
            return $tokens;
        }

        $n = count($clusterTokens);
        $out = [];
        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            $removed = false;
            for ($len = $n; $len >= 2; $len--) {
                for ($start = 0; $start <= $n - $len; $start++) {
                    $span = array_slice($clusterTokens, $start, $len);
                    if (array_slice($tokens, $i, $len) === $span) {
                        $i += $len;
                        $removed = true;
                        break 2;
                    }
                }
            }
            if ($removed) {
                continue;
            }
            if (in_array($tokens[$i], $clusterTokens, true)) {
                $i++;

                continue;
            }
            $out[] = $tokens[$i];
            $i++;
        }

        return $out;
    }

    /**
     * @param  list<string>  $kwTokens
     * @param  list<string>  $clTokens
     * @return list<string>
     */
    private function semanticResidual(string $keywordPhrase, string $clusterCanonical, array $kwTokens, array $clTokens): array
    {
        if ($this->phraseResolver->hasServiceIntent($clusterCanonical)) {
            $clLead = $this->servicePrefixLength($clTokens);
            if ($clLead > 0 && $this->startsWithTokens($kwTokens, array_slice($clTokens, 0, $clLead))) {
                return array_slice($kwTokens, $clLead);
            }
        }

        $commonPrefix = $this->commonPrefixLength($kwTokens, $clTokens);
        if ($commonPrefix >= 2) {
            return array_slice($kwTokens, $commonPrefix);
        }

        return array_values(array_diff($kwTokens, $clTokens));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function servicePrefixLength(array $tokens): int
    {
        if ($tokens === []) {
            return 0;
        }

        if ($tokens[0] === 'xuong' && ($tokens[1] ?? '') === 'may') {
            return min(3, count($tokens));
        }

        if ($tokens[0] === 'may') {
            return min(2, count($tokens));
        }

        return 0;
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $prefix
     */
    private function startsWithTokens(array $haystack, array $prefix): bool
    {
        if (count($prefix) > count($haystack)) {
            return false;
        }

        for ($i = 0; $i < count($prefix); $i++) {
            if ($haystack[$i] !== $prefix[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function commonPrefixLength(array $a, array $b): int
    {
        $len = min(count($a), count($b));
        $i = 0;
        while ($i < $len && $a[$i] === $b[$i]) {
            $i++;
        }

        return $i;
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    private function containsSubsequence(array $haystack, array $needle): bool
    {
        if ($needle === []) {
            return true;
        }

        $ni = 0;
        foreach ($haystack as $token) {
            if ($token === $needle[$ni]) {
                $ni++;
                if ($ni === count($needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     * @return list<string>
     */
    private function residualTokens(array $haystack, array $needle): array
    {
        $extra = [];
        $ni = 0;
        foreach ($haystack as $token) {
            if ($ni < count($needle) && $token === $needle[$ni]) {
                $ni++;

                continue;
            }
            $extra[] = $token;
        }

        return $extra;
    }

    /**
     * @param  list<string>  $residual
     * @return list<string>
     */
    private function groupRemainingTokens(string $originalPhrase, array $residual): array
    {
        if ($residual === []) {
            return [];
        }

        $chunks = [];
        $buffer = [];
        foreach ($residual as $token) {
            if (in_array($token, self::GLUE, true) || mb_strlen($token) < 2) {
                if ($buffer !== []) {
                    $chunks[] = implode(' ', $buffer);
                    $buffer = [];
                }

                continue;
            }
            $buffer[] = $token;
        }
        if ($buffer !== []) {
            $chunks[] = implode(' ', $buffer);
        }

        return array_values(array_filter(
            array_map(fn (string $c): string => $this->displayFragment($originalPhrase, $c), $chunks),
            static fn (string $c): bool => trim($c) !== '',
        ));
    }

    /**
     * @param  list<string>  $residual
     * @return list<string>
     */
    private function removePatternTokens(array $residual, string $pattern): array
    {
        $patternTokens = array_values(array_filter(explode(' ', $pattern)));
        $out = [];
        $i = 0;
        while ($i < count($residual)) {
            $slice = array_slice($residual, $i, count($patternTokens));
            if ($slice === $patternTokens) {
                $i += count($patternTokens);

                continue;
            }
            $out[] = $residual[$i];
            $i++;
        }

        return $out;
    }

    private function displayFragment(string $originalPhrase, string $foldedFragment): string
    {
        $patternParts = array_values(array_filter(
            preg_split('/\s+/u', trim($foldedFragment)) ?: [],
            static fn (string $t): bool => $t !== '',
        ));
        if ($patternParts === []) {
            return '';
        }

        $rawWords = preg_split('/\s+/u', trim($originalPhrase)) ?: [];
        $displayParts = [];
        $pi = 0;
        foreach ($rawWords as $rawWord) {
            if ($pi >= count($patternParts)) {
                break;
            }
            $folded = $this->normalizer->fold(mb_strtolower($rawWord, 'UTF-8'));
            if ($folded === $patternParts[$pi]) {
                $displayParts[] = $rawWord;
                $pi++;
            }
        }

        if ($pi === count($patternParts) && $displayParts !== []) {
            return implode(' ', $displayParts);
        }

        return $foldedFragment;
    }

    private function guessFacet(string $normalized): ?string
    {
        return self::KNOWN_FACETS[$normalized] ?? null;
    }
}
