<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordQuality;

use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;

/**
 * Fixed quality tags. A tag is a marker, not a trash verdict.
 */
final class KeywordQualityClassifier
{
    /** @var list<string> */
    private const CTA = [
        'mua ngay',
        'xem them',
        'click tai day',
        'lien he ngay',
        'bam vao day',
        'dat hang ngay',
        'goi ngay',
        'click here',
        'buy now',
    ];

    /** @var list<string> */
    private const GENERIC = [
        'tai day',
        'chi tiet',
        'san pham nay',
        'xem chi tiet',
        'doc them',
        'read more',
    ];

    /** @var list<string> */
    private const SENTENCE_FILLERS = [
        'su lua chon',
        'cua nguoi',
        'la mot',
        'duoc thiet ke',
        'phu hop voi',
        'danh cho nhung',
        'se giup',
        'khong chi',
    ];

    /** @var list<string> */
    private const QUESTION_STARTS = [
        'lam sao',
        'lam the nao',
        'tai sao',
        'cach ',
        'o dau',
        'bao nhieu',
        'nhu the nao',
        'co nen',
    ];

    /** @var list<string> */
    private const SURNAMES = [
        'nguyen', 'tran', 'pham', 'hoang', 'huynh', 'phan', 'dang', 'bui', 'ngo', 'duong',
        'le', 'ly', 'ho', 'do', 'vu', 'vo',
    ];

    public function __construct(
        private readonly KeywordNormalizer $normalizer = new KeywordNormalizer,
    ) {}

    /**
     * @param  array<int, string>  $normalizedById
     * @return list<int> keyword ids that are non-canonical duplicates
     */
    public function duplicateKeywordIds(array $normalizedById): array
    {
        $groups = [];
        foreach ($normalizedById as $id => $normalized) {
            $normalized = trim($normalized);
            if ($normalized === '' || (int) $id <= 0) {
                continue;
            }
            $groups[$normalized][] = (int) $id;
        }

        $duplicates = [];
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            sort($ids);
            array_shift($ids);
            foreach ($ids as $id) {
                $duplicates[] = $id;
            }
        }

        return $duplicates;
    }

    /**
     * @return list<string>
     */
    public function classify(string $raw, bool $isDuplicateVariant = false): array
    {
        $normalized = $this->normalizer->normalize($raw)['normalized_text'];
        $folded = $this->normalizer->fold($normalized);
        $codes = [];

        if ($this->matchesShortPhrase($folded, self::CTA)) {
            $codes[] = KeywordQualityCode::CTA_ANCHOR;
        } elseif ($this->matchesShortPhrase($folded, self::GENERIC)) {
            $codes[] = KeywordQualityCode::GENERIC_ANCHOR;
        }

        if ($this->isEncodingError($raw)) {
            $codes[] = KeywordQualityCode::ENCODING_ERROR;
        }

        if ($this->isMalformed($raw)) {
            $codes[] = KeywordQualityCode::MALFORMED_TEXT;
        }

        if ($this->isSentenceLike($raw, $folded)) {
            $codes[] = KeywordQualityCode::SENTENCE_LIKE;
        }

        if ($this->isSuspectProperName($folded)) {
            $codes[] = KeywordQualityCode::SUSPECT_PROPER_NAME;
        }

        if ($isDuplicateVariant) {
            $codes[] = KeywordQualityCode::DUPLICATE_VARIANT;
        }

        return $codes;
    }

    public function normalized(string $raw): string
    {
        return $this->normalizer->normalize($raw)['normalized_text'];
    }

    /**
     * @param  list<string>  $phrases
     */
    private function matchesShortPhrase(string $folded, array $phrases): bool
    {
        if ($folded === '') {
            return false;
        }

        $tokens = preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokenCount = count($tokens);
        foreach ($phrases as $phrase) {
            if ($folded === $phrase) {
                return true;
            }
            if ($tokenCount > 4 || $tokenCount === 0) {
                continue;
            }
            if (preg_match('/(?:^|\s)'.preg_quote($phrase, '/').'(?:\s|$)/u', $folded) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isEncodingError(string $raw): bool
    {
        return preg_match('/&(?:amp|lt|gt|quot|nbsp|#\d+|#x[0-9a-f]+);/iu', $raw) === 1
            || str_contains($raw, "\u{FFFD}");
    }

    private function isMalformed(string $raw): bool
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/[\x{200B}\x{FEFF}\x{00AD}]/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/[,:;]$/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^[,:;]/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/([,;:])\1/u', $trimmed) === 1) {
            return true;
        }

        $quotes = preg_match_all('/["“”]/u', $trimmed);

        return is_int($quotes) && $quotes > 0 && $quotes % 2 === 1;
    }

    private function isSentenceLike(string $raw, string $folded): bool
    {
        if ($this->isSearchQuestion($raw, $folded)) {
            return false;
        }

        $tokens = preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 8) {
            return false;
        }

        $hasClause = preg_match('/\p{L}{3,},\s+\p{L}/u', $raw) === 1;
        $endsSentence = preg_match('/[.!…]["\']?\s*$/u', trim($raw)) === 1;
        if (! $hasClause && ! $endsSentence) {
            return false;
        }

        foreach (self::SENTENCE_FILLERS as $filler) {
            if (str_contains($folded, $filler)) {
                return true;
            }
        }

        return false;
    }

    private function isSearchQuestion(string $raw, string $folded): bool
    {
        if (str_ends_with(trim($raw), '?')) {
            return true;
        }

        foreach (self::QUESTION_STARTS as $start) {
            if (str_starts_with($folded, $start)) {
                return true;
            }
        }

        return str_contains($folded, ' loai nao ')
            || str_contains($folded, ' nhu the nao');
    }

    private function isSuspectProperName(string $folded): bool
    {
        $tokens = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 3) {
            return false;
        }

        $surname = array_fill_keys(self::SURNAMES, true);
        $hasNonName = false;
        foreach ($tokens as $token) {
            if (! isset($surname[$token]) && ! in_array($token, ['van', 'thi', 'anh', 'chi'], true)) {
                $hasNonName = true;
                break;
            }
        }
        if (! $hasNonName) {
            return false;
        }

        $count = count($tokens);
        for ($i = 0; $i < $count - 1; $i++) {
            $current = $tokens[$i];
            $next = $tokens[$i + 1];
            if (isset($surname[$current], $surname[$next]) && (strlen($current) >= 4 || strlen($next) >= 4)) {
                return true;
            }
            if (in_array($current, ['van', 'thi'], true)) {
                $follow = $tokens[$i + 1] ?? '';
                $after = $tokens[$i + 2] ?? '';
                if ((isset($surname[$follow]) && strlen($follow) >= 4) || (isset($surname[$after]) && strlen($after) >= 4)) {
                    return true;
                }
            }
        }

        return false;
    }
}
