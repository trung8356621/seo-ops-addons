<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Support;

use Omnichannel\Addons\SearchFoundation\Services\MatchRules\MatchRuleMatcher;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

/**
 * Derives intent-safe canonical topic phrases from keyword text.
 */
final class TopicPhraseResolver
{
    public function __construct(
        private readonly KeywordNormalizer $normalizer,
        private readonly KeywordCanonicalizer $canonicalizer,
        private readonly array $serviceIntentMarkers = [],
        private readonly array $genericCores = [],
        private readonly ?MatchRuleMatcher $matchRuleMatcher = null,
        private readonly array $discoursePrefixes = [],
        private readonly array $glueTokens = [],
    ) {}

    public function withRules(array $industryRules, array $globalRules): self
    {
        return new self(
            $this->normalizer,
            $this->canonicalizer,
            (array) ($industryRules['service_intent_terms'] ?? []),
            (array) ($industryRules['generic_cores'] ?? []),
            $this->matchRuleMatcher ?? new MatchRuleMatcher,
            $this->normalizeTerms((array) ($globalRules['discourse_prefixes'] ?? [])),
            $this->normalizeTerms((array) ($globalRules['topic_glue_terms'] ?? [])),
        );
    }

    /**
     * Pick the best canonical display phrase from member phrases.
     *
     * @param  list<string>  $phrases
     */
    public function pickCanonicalFromMembers(array $phrases): string
    {
        $candidates = [];
        foreach ($phrases as $phrase) {
            $derived = $this->deriveCorePhrase($phrase);
            if ($derived !== '') {
                $candidates[] = $derived;
            }
        }

        if ($candidates === []) {
            return '';
        }

        $best = '';
        $bestScore = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $norm = $this->normalizer->normalize($candidate);
            $score = mb_strlen($norm['folded_text']) * 1000
                - $this->canonicalizer->displayScore($candidate, $norm['normalized_text']);
            if ($score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public function deriveCorePhrase(string $phrase): string
    {
        $raw = trim($phrase);
        if ($raw === '') {
            return '';
        }

        $norm = $this->normalizer->normalize($raw);
        $folded = $norm['folded_text'];
        $tokens = $this->tokens($folded);
        if ($tokens === []) {
            return $raw;
        }

        $tokens = $this->stripDiscoursePrefix($tokens);
        $tokens = $this->stripLeadingServiceIntent($raw, $tokens);

        $rebuilt = $this->rebuildDisplay($raw, $tokens);

        return $rebuilt !== '' ? $rebuilt : $raw;
    }

    public function hasServiceIntent(string $phrase): bool
    {
        if ($this->matchRuleMatcher !== null && $this->entries($this->serviceIntentMarkers) !== []) {
            return $this->matchRuleMatcher->matches($this->entries($this->serviceIntentMarkers), $phrase);
        }
        $folded = $this->normalizer->normalize($phrase)['folded_text'];
        $tokens = $this->tokens($folded);
        if ($tokens === []) {
            return false;
        }

        $joined = implode(' ', $tokens);
        $padded = ' '.$joined.' ';

        foreach ($this->terms($this->serviceIntentMarkers) as $marker) {
            $marker = trim((string) $marker);
            if ($marker === '') {
                continue;
            }
            if ($joined === $marker || str_contains($padded, ' '.$marker.' ')) {
                return true;
            }
        }

        return false;
    }

    public function hasAccentSensitiveServiceConflict(string $phrase): bool
    {
        if ($this->matchRuleMatcher === null) {
            return false;
        }
        $foldedPhrase = $this->normalizer->normalize($phrase)['folded_text'];
        $normalizedPhrase = mb_strtolower($this->normalizer->normalize($phrase)['normalized_text']);
        foreach ($this->entries($this->serviceIntentMarkers) as $entry) {
            if (($entry['match_mode'] ?? '') !== 'accent_sensitive' || $this->matchRuleMatcher->matches([$entry], $phrase)) {
                continue;
            }
            foreach ([(string) ($entry['canonical'] ?? ''), ...array_map('strval', (array) ($entry['aliases'] ?? []))] as $term) {
                $foldedTerm = $this->normalizer->normalize($term)['folded_text'];
                if ($foldedTerm !== '' && str_contains(' '.$foldedPhrase.' ', ' '.$foldedTerm.' ')) {
                    return true;
                }
                $normalizedTerm = mb_strtolower($this->normalizer->normalize($term)['normalized_text']);
                foreach ($this->tokens($foldedTerm) as $index => $foldedToken) {
                    $sensitiveToken = $this->tokens($normalizedTerm)[$index] ?? '';
                    if ($sensitiveToken !== ''
                        && str_contains(' '.$foldedPhrase.' ', ' '.$foldedToken.' ')
                        && ! str_contains(' '.$normalizedPhrase.' ', ' '.$sensitiveToken.' ')
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function intentCompatible(string $phraseA, string $phraseB): bool
    {
        $aService = $this->hasServiceIntent($phraseA);
        $bService = $this->hasServiceIntent($phraseB);

        if ($aService !== $bService) {
            return false;
        }

        $aTokens = $this->significantTokens($phraseA);
        $bTokens = $this->significantTokens($phraseB);

        if ($aService) {
            $aLead = $this->serviceLeadTokens($aTokens);
            $bLead = $this->serviceLeadTokens($bTokens);
            if ($aLead !== [] && $bLead !== [] && $aLead !== $bLead) {
                // Compatible when one service lead is a contiguous core inside the other phrase.
                if ($this->containsContiguousTokenPhrase($aTokens, $bLead)
                    || $this->containsContiguousTokenPhrase($bTokens, $aLead)
                ) {
                    return true;
                }

                return false;
            }
        }

        return true;
    }

    /**
     * Token-boundary containment: keyword contains the full canonical core as a
     * CONTIGUOUS token phrase (prefix / mid / suffix). Gaps are not allowed.
     *
     * Includes service/product intent compatibility (legacy callers).
     * For Topic membership umbrella matching use {@see containsCanonicalCoreForTopic()}.
     */
    public function containsCanonicalCore(string $keywordPhrase, string $canonicalPhrase): bool
    {
        if (trim($keywordPhrase) === '' || trim($canonicalPhrase) === '') {
            return false;
        }

        if (! $this->intentCompatible($keywordPhrase, $canonicalPhrase)) {
            return false;
        }

        return $this->containsContiguousCanonicalTokens($keywordPhrase, $canonicalPhrase);
    }

    /**
     * Topic membership containment: same contiguous-token core check WITHOUT
     * service/product intent as a hard gate.
     *
     * Topics are umbrella entities — product + service + local modifiers may share
     * one Topic when the canonical core is genuinely present.
     */
    public function containsCanonicalCoreForTopic(string $keywordPhrase, string $canonicalPhrase): bool
    {
        if (trim($keywordPhrase) === '' || trim($canonicalPhrase) === '') {
            return false;
        }

        $coreTokens = $this->significantTokens($canonicalPhrase);
        if ($this->isGenericSingletonCore($coreTokens)) {
            return false;
        }

        return $this->containsContiguousCanonicalTokens($keywordPhrase, $canonicalPhrase);
    }

    private function containsContiguousCanonicalTokens(string $keywordPhrase, string $canonicalPhrase): bool
    {
        $keywordTokens = $this->significantTokens($keywordPhrase);
        $coreTokens = $this->significantTokens($canonicalPhrase);
        if ($coreTokens === [] || count($keywordTokens) < count($coreTokens)) {
            return false;
        }

        return $this->containsContiguousTokenPhrase($keywordTokens, $coreTokens);
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    public function containsContiguousTokenPhrase(array $haystack, array $needle): bool
    {
        if ($needle === []) {
            return true;
        }

        $needleCount = count($needle);
        $hayCount = count($haystack);
        if ($needleCount > $hayCount) {
            return false;
        }

        $limit = $hayCount - $needleCount;
        for ($i = 0; $i <= $limit; $i++) {
            $ok = true;
            for ($j = 0; $j < $needleCount; $j++) {
                if ($haystack[$i + $j] !== $needle[$j]) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * Singleton cores that must not drive Pass-2 containment alone.
     *
     * @param  list<string>  $tokens
     */
    public function isGenericSingletonCore(array $tokens): bool
    {
        if (count($tokens) !== 1) {
            return false;
        }

        if ($this->matchRuleMatcher !== null && $this->entries($this->genericCores) !== []) {
            return $this->matchRuleMatcher->matches($this->entries($this->genericCores), implode(' ', $tokens));
        }

        return in_array($tokens[0], $this->normalizeTerms($this->terms($this->genericCores)), true);
    }

    /**
     * Prefer service-core display when present; else deriveCorePhrase.
     */
    public function preferredClusterCore(string $phrase): string
    {
        $serviceCore = $this->extractServiceCoreDisplay($phrase);
        if ($serviceCore !== '') {
            return $serviceCore;
        }

        return $this->deriveCorePhrase($phrase);
    }

    /**
     * Rebuild display for a configured service-intent lead found anywhere in the phrase.
     */
    public function extractServiceCoreDisplay(string $phrase): string
    {
        if (! $this->hasServiceIntent($phrase)) {
            return '';
        }

        return $this->deriveCorePhrase($phrase);
    }

    /**
     * Whether $longer is a safe superset of $shorter (boilerplate-only extra wording).
     */
    public function isBoilerplateSuperset(string $longer, string $shorter): bool
    {
        if (! $this->intentCompatible($longer, $shorter)) {
            return false;
        }

        $longTokens = $this->significantTokens($longer);
        $shortTokens = $this->significantTokens($shorter);
        if ($shortTokens === [] || count($longTokens) < count($shortTokens)) {
            return false;
        }

        if (! $this->containsTokenSubsequence($longTokens, $shortTokens)) {
            return false;
        }

        $extra = $this->extraTokens($longTokens, $shortTokens);

        return $extra === [] || $this->allGlueOrDiscourse($extra);
    }

    /**
     * Whether $candidate is a better (shorter) canonical for $existing.
     */
    public function shouldPromoteCanonical(string $existing, string $candidate): bool
    {
        if (! $this->intentCompatible($existing, $candidate)) {
            return false;
        }

        // Allow modifier-bearing phrases to promote down to a contained shorter core.
        if ($this->containsCanonicalCore($existing, $candidate)
            && mb_strlen($this->normalizedKey($candidate)) < mb_strlen($this->normalizedKey($existing))
        ) {
            return true;
        }

        $existingCore = $this->preferredClusterCore($existing) ?: $this->deriveCorePhrase($existing);
        $candidateCore = $this->preferredClusterCore($candidate) ?: $this->deriveCorePhrase($candidate);

        if ($candidateCore === '' || $existingCore === '') {
            return false;
        }

        if ($this->normalizedKey($existingCore) === $this->normalizedKey($candidateCore)) {
            return mb_strlen($candidate) < mb_strlen($existing);
        }

        return $this->isBoilerplateSuperset($existing, $candidate);
    }

    public function normalizedKey(string $phrase): string
    {
        return $this->canonicalizer->exactKey($this->normalizer->normalize($phrase)['folded_text']);
    }

    /**
     * @return list<string>
     */
    public function significantTokens(string $phrase): array
    {
        $folded = $this->normalizer->normalize($phrase)['folded_text'];
        $tokens = $this->tokens($folded);

        return array_values(array_filter(
            $tokens,
            fn (string $t): bool => ! in_array($t, $this->glueTokens, true) && mb_strlen($t) >= 2,
        ));
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function stripDiscoursePrefix(array $tokens): array
    {
        $working = $tokens;
        while ($working !== []) {
            $joined = implode(' ', $working);
            $stripped = false;
            foreach ($this->discoursePrefixes as $prefix) {
                $prefixTokens = $this->tokens($prefix);
                if ($this->startsWith($working, $prefixTokens)) {
                    $working = array_slice($working, count($prefixTokens));
                    $stripped = true;
                    break;
                }
            }
            if (! $stripped) {
                break;
            }
        }

        return array_values($working);
    }

    /**
     * Strip "dich vu" when it leads a service phrase (e.g. "dich vu may ...").
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function stripLeadingServiceIntent(string $phrase, array $tokens): array
    {
        foreach ($this->serviceIntentMarkers as $marker) {
            $entries = is_array($marker) ? [$marker] : [];
            if ($entries !== [] && $this->matchRuleMatcher !== null && ! $this->matchRuleMatcher->matches($entries, $phrase)) {
                continue;
            }
            $values = is_array($marker)
                ? [(string) ($marker['canonical'] ?? ''), ...array_map('strval', (array) ($marker['aliases'] ?? []))]
                : [(string) $marker];
            foreach ($values as $value) {
                $markerTokens = $this->tokens($this->normalizer->normalize($value)['folded_text']);
                if ($markerTokens !== [] && $this->startsWith($tokens, $markerTokens)) {
                    return array_values(array_slice($tokens, count($markerTokens)));
                }
            }
        }

        return $tokens;
    }

    /**
     * @param  list<string>  $originalTokens  folded tokens after cleanup
     */
    private function rebuildDisplay(string $raw, array $originalTokens): string
    {
        if ($originalTokens === []) {
            return '';
        }

        $target = implode(' ', $originalTokens);
        $rawNorm = mb_strtolower($this->normalizer->normalize($raw)['normalized_text'], 'UTF-8');
        $words = preg_split('/\s+/u', $rawNorm) ?: [];
        $out = [];
        $ti = 0;
        foreach ($words as $word) {
            $folded = $this->normalizer->fold($word);
            if ($ti < count($originalTokens) && $folded === $originalTokens[$ti]) {
                $out[] = $this->extractOriginalWord($raw, $word);
                $ti++;
            }
        }

        if ($ti === count($originalTokens) && $out !== []) {
            return implode(' ', $out);
        }

        return $this->canonicalizer->prettyLabel($target);
    }

    private function extractOriginalWord(string $raw, string $lowerWord): string
    {
        $pattern = '/\b'.preg_quote($lowerWord, '/').'\b/ui';
        if (preg_match($pattern, $raw, $m)) {
            return $m[0];
        }

        return $lowerWord;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $folded): array
    {
        return array_values(array_filter(
            preg_split('/\s+/u', trim($folded)) ?: [],
            static fn (string $t): bool => $t !== '' && mb_strlen($t) >= 2,
        ));
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    private function startsWith(array $haystack, array $needle): bool
    {
        if (count($needle) > count($haystack)) {
            return false;
        }

        for ($i = 0; $i < count($needle); $i++) {
            if ($haystack[$i] !== $needle[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    private function containsTokenSubsequence(array $haystack, array $needle): bool
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
     * @param  list<string>  $longer
     * @param  list<string>  $shorter
     * @return list<string>
     */
    private function extraTokens(array $longer, array $shorter): array
    {
        if (! $this->containsTokenSubsequence($longer, $shorter)) {
            return $longer;
        }

        $extra = [];
        $si = 0;
        foreach ($longer as $token) {
            if ($si < count($shorter) && $token === $shorter[$si]) {
                $si++;

                continue;
            }
            $extra[] = $token;
        }

        return $extra;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function allGlueOrDiscourse(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (! in_array($token, $this->glueTokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function serviceLeadTokens(array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        $joined = implode(' ', $tokens);
        foreach ($this->terms($this->serviceIntentMarkers) as $marker) {
            $markerTokens = $this->tokens(trim((string) $marker));
            if ($markerTokens !== [] && ($joined === implode(' ', $markerTokens) || str_contains(' '.$joined.' ', ' '.implode(' ', $markerTokens).' '))) {
                return $markerTokens;
            }
        }

        return array_slice($tokens, 0, 1);
    }

    private function entries(array $values): array
    {
        return array_values(array_filter($values, static fn ($value): bool => is_array($value)));
    }

    private function terms(array $values): array
    {
        $terms = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $terms[] = (string) ($value['canonical'] ?? '');
            } else {
                $terms[] = (string) $value;
            }
        }

        return array_values(array_filter($terms, static fn (string $term): bool => trim($term) !== ''));
    }

    private function normalizeTerms(array $terms): array
    {
        return array_values(array_filter(array_map(
            fn ($term): string => $this->normalizer->normalize((string) $term)['folded_text'],
            $terms,
        )));
    }

    /**
     * @param  list<string>  $tokens
     */
    /**
     * @param  list<string>  $tokens
     */
    private function indexOfToken(array $tokens, string $needle): int
    {
        foreach ($tokens as $i => $token) {
            if ($token === $needle) {
                return (int) $i;
            }
        }

        return -1;
    }

    /**
     * @param  list<string>  $leadTokens
     */
    private function rebuildLeadDisplay(string $raw, array $leadTokens): string
    {
        if ($leadTokens === []) {
            return '';
        }

        $words = preg_split('/\s+/u', trim($raw)) ?: [];
        $out = [];
        $ti = 0;
        foreach ($words as $word) {
            $folded = $this->normalizer->fold(preg_replace('/[^\p{L}\p{N}]+/u', '', $word) ?? $word);
            if ($folded === '') {
                continue;
            }
            if ($ti < count($leadTokens) && $folded === $leadTokens[$ti]) {
                $out[] = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $word) ?: $word;
                $ti++;
            }
            if ($ti >= count($leadTokens)) {
                break;
            }
        }

        if ($ti === count($leadTokens) && $out !== []) {
            return implode(' ', $out);
        }

        return $this->canonicalizer->prettyLabel(implode(' ', $leadTokens));
    }
}
