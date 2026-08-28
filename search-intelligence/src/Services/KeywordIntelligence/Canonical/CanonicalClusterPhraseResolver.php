<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Canonical;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

/**
 * Derives intent-safe canonical cluster phrases from keyword text.
 */
final class CanonicalClusterPhraseResolver
{
    /** @var list<string> */
    private const DISCOURSE_PREFIXES = [
        'tham khao',
        'tim hieu',
        'xem them',
        'thong tin ve',
        'thong tin',
    ];

    /** @var list<string> */
    private const SERVICE_INTENT_MARKERS = [
        'xuong may',
        'dich vu may',
        'dich vu',
        'san xuat',
        'gia si',
        'theo yeu cau',
        'may',
    ];

    /** @var list<string> */
    private const GLUE_TOKENS = [
        'tai', 'o', 'cho', 'la', 'cua', 'va', 'voi', 'den', 'tu', 'trong', 'theo',
    ];

    public function __construct(
        private readonly KeywordNormalizer $normalizer,
        private readonly KeywordCanonicalizer $canonicalizer,
    ) {}

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
        $tokens = $this->stripLeadingDiscourseService($tokens);

        $rebuilt = $this->rebuildDisplay($raw, $tokens);

        return $rebuilt !== '' ? $rebuilt : $raw;
    }

    public function hasServiceIntent(string $phrase): bool
    {
        $folded = $this->normalizer->normalize($phrase)['folded_text'];
        $tokens = $this->tokens($folded);
        if ($tokens === []) {
            return false;
        }

        $joined = implode(' ', $tokens);
        $padded = ' '.$joined.' ';

        // Mid-phrase OK (e.g. "Hợp Phát - xưởng may balo ..."). Skip bare "may" here.
        foreach (['xuong may', 'dich vu may', 'dich vu', 'san xuat', 'gia si', 'theo yeu cau'] as $marker) {
            if ($joined === $marker || str_contains($padded, ' '.$marker.' ')) {
                return true;
            }
        }

        foreach (self::SERVICE_INTENT_MARKERS as $marker) {
            if ($marker === 'may') {
                continue;
            }
            if (str_starts_with($joined, $marker.' ') || $joined === $marker) {
                return true;
            }
        }

        return in_array('may', $tokens, true) && ! in_array('xuong', $tokens, true);
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
     * Example MATCH: "hợp phát xưởng may balo giá rẻ" ⊇ "xưởng may balo"
     * Example NO:    "may chuyên dụng cho da" ⊉ "may balo da"
     */
    public function containsCanonicalCore(string $keywordPhrase, string $canonicalPhrase): bool
    {
        if (trim($keywordPhrase) === '' || trim($canonicalPhrase) === '') {
            return false;
        }

        if (! $this->intentCompatible($keywordPhrase, $canonicalPhrase)) {
            return false;
        }

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

        return in_array($tokens[0], ['may', 'balo', 'tui', 'vai', 'gia', 'da'], true);
    }

    /**
     * Prefer service-core display ("Xưởng may balo") when present; else deriveCorePhrase.
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
     * Rebuild display for xuong/may/(product) lead found anywhere in the phrase.
     */
    public function extractServiceCoreDisplay(string $phrase): string
    {
        $tokens = $this->significantTokens($phrase);
        $lead = $this->serviceLeadTokens($tokens);
        if ($lead === [] || ($lead[0] ?? '') !== 'xuong') {
            // Leading "may …" service cores (e.g. May Túi Vải Canvas)
            if (($lead[0] ?? '') === 'may' && count($tokens) >= 2) {
                $mayLead = array_slice($tokens, $this->indexOfToken($tokens, 'may'), min(3, count($tokens)));

                return $this->rebuildLeadDisplay($phrase, $mayLead);
            }

            return '';
        }

        $lead = array_values(array_filter($lead, static fn (string $t): bool => $t !== ''));

        return $this->rebuildLeadDisplay($phrase, $lead);
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
            static fn (string $t): bool => ! in_array($t, self::GLUE_TOKENS, true) && mb_strlen($t) >= 2,
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
            foreach (self::DISCOURSE_PREFIXES as $prefix) {
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
     * Strip "dịch vụ" when it leads a service phrase (e.g. "dịch vụ may ...").
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function stripLeadingDiscourseService(array $tokens): array
    {
        if (count($tokens) >= 2 && $tokens[0] === 'dich' && $tokens[1] === 'vu') {
            $rest = array_slice($tokens, 2);
            if ($rest !== [] && ($rest[0] === 'may' || str_starts_with(implode(' ', $rest), 'may '))) {
                return $rest;
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
     * @param  list<string>  $tokens
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
            if (! in_array($token, self::GLUE_TOKENS, true)) {
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

        $xuongIdx = $this->indexOfXuongMay($tokens);
        if ($xuongIdx >= 0) {
            $lead = ['xuong', 'may'];
            if (($tokens[$xuongIdx + 2] ?? '') !== '') {
                $lead[] = $tokens[$xuongIdx + 2];
            }

            return $lead;
        }

        $mayIdx = $this->indexOfToken($tokens, 'may');
        if ($mayIdx >= 0 && ! in_array('xuong', $tokens, true)) {
            return ['may'];
        }

        if ($tokens[0] === 'dich' && ($tokens[1] ?? '') === 'vu') {
            return array_slice($tokens, 0, min(3, count($tokens)));
        }

        return array_slice($tokens, 0, 1);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function indexOfXuongMay(array $tokens): int
    {
        $count = count($tokens);
        for ($i = 0; $i < $count - 1; $i++) {
            if ($tokens[$i] === 'xuong' && $tokens[$i + 1] === 'may') {
                return $i;
            }
        }

        return -1;
    }

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
