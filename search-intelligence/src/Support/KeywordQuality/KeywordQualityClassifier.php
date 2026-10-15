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
        'khang dinh',
        'phong cach thoi trang',
        'ca tinh',
        'trai nghiem',
        'dang cap',
        'sang trong',
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

    /** @var list<string> */
    private const GIVEN_NAMES = [
        'an', 'binh', 'dung', 'hung', 'lan', 'hoa', 'mai', 'linh', 'minh', 'tuan', 'duc', 'long',
        'khoa', 'huy', 'quan', 'trang', 'thu', 'huong', 'ngoc', 'phuong', 'thao', 'khanh', 'dat',
        'son', 'tai', 'trung', 'cuong', 'thanh', 'hieu', 'kiet', 'bao', 'duy', 'giang', 'hanh',
        'hien', 'hong', 'hue', 'lam', 'loc', 'nga', 'nhi', 'nhu', 'oanh', 'phuc', 'quang', 'quyen',
        'sang', 'tam', 'thuy', 'tien', 'tram', 'truc', 'tung', 'viet', 'vuong', 'xuan', 'yen',
    ];

    /** @var list<string> */
    private const WESTERN_GIVEN = [
        'john', 'mary', 'david', 'michael', 'james', 'robert', 'anna', 'emma', 'olivia', 'daniel',
        'peter', 'paul', 'sarah', 'lisa', 'kevin', 'jason', 'andrew', 'thomas', 'george', 'henry',
        'charles', 'elizabeth', 'jennifer', 'emily', 'alexander', 'benjamin', 'samuel', 'joseph',
        'christopher', 'matthew', 'william', 'richard', 'anthony', 'steven', 'edward', 'brian',
    ];

    /** @var list<string> */
    private const WESTERN_FAMILY = [
        'smith', 'johnson', 'williams', 'brown', 'jones', 'garcia', 'miller', 'davis', 'wilson',
        'anderson', 'taylor', 'moore', 'jackson', 'martin', 'thompson', 'white', 'harris', 'clark',
        'lewis', 'robinson', 'walker', 'young', 'allen', 'king', 'wright', 'green', 'baker', 'adams',
        'nelson', 'carter', 'mitchell', 'roberts', 'turner', 'phillips', 'campbell', 'parker', 'evans',
        'edwards', 'collins', 'stewart', 'morris', 'murphy', 'cooper', 'bailey', 'bennett', 'watson',
    ];

    /** @var list<string> */
    private const BRANDS = [
        'adidas', 'nike', 'puma', 'converse', 'fila', 'asics', 'reebok', 'gucci', 'uniqlo', 'zara',
        'apple', 'samsung', 'sony', 'xiaomi', 'honda', 'yamaha', 'canon', 'jansport', 'herschel',
        'eastpak', 'tumi', 'samsonite',
    ];

    /** @var list<string> */
    private const ORG_TOKENS = [
        'global', 'international', 'academy', 'school', 'college', 'center', 'centre', 'institute', 'kindergarten',
    ];

    /** @var list<string> */
    private const COUNTRIES = [
        'australia', 'vietnam', 'america', 'usa', 'japan', 'korea', 'singapore', 'canada', 'france',
        'germany', 'china', 'thailand', 'malaysia', 'indonesia', 'england', 'philippines',
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

        if ($this->isTrailingSequenceNumber($folded)) {
            $codes[] = KeywordQualityCode::TRAILING_SEQUENCE_NUMBER;
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
        $hasTitleClause = preg_match('/\p{L}\s+[-–—]\s+\p{L}/u', $raw) === 1;
        if (! $hasClause && ! $endsSentence && ! $hasTitleClause) {
            return false;
        }

        foreach (self::SENTENCE_FILLERS as $filler) {
            if (str_contains($folded, $filler)) {
                return true;
            }
        }

        return false;
    }

    private function isTrailingSequenceNumber(string $folded): bool
    {
        if (preg_match('/(?:^|\s)\d{1,2}$/u', $folded) !== 1) {
            return false;
        }

        if ($this->isRecognizedModel($folded)) {
            return false;
        }

        $tokens = preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $before = $tokens[count($tokens) - 2] ?? '';

        return ! in_array($before, [
            'inch', 'inches', 'cm', 'mm', 'kg', 'ml', 'gb', 'tb', 'oz', 'size', 'co', 'lit',
            'liter', 'litre', 'mah', 'dpi', 'px', 'km', 'top', 'so', 'hang', 'no', 'number', 'stt', 'l', 'm',
        ], true);
    }

    private function isRecognizedModel(string $folded): bool
    {
        return preg_match('/(?:air\s+max|air\s+force|air\s+jordan|ultra\s*boost|yeezy|stan\s+smith|superstar|air\s+zoom)\s+\d{1,2}$/u', $folded) === 1;
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
        if (count($tokens) < 3 || $this->isRecognizedModel($folded)) {
            return false;
        }

        $surname = array_fill_keys(self::SURNAMES, true);
        $given = array_fill_keys(self::GIVEN_NAMES, true);
        $particles = ['van' => true, 'thi' => true];
        $hasContext = false;
        foreach ($tokens as $token) {
            $namePart = isset($surname[$token]) || isset($given[$token]) || isset($particles[$token]) || $token === 'anh' || $token === 'chi';
            if (! $namePart) {
                $hasContext = true;
                break;
            }
        }
        if (! $hasContext) {
            return false;
        }

        if ($this->hasVietnamesePerson($tokens, $surname, $given) || $this->hasForeignPerson($tokens)) {
            return true;
        }

        return $this->hasInstitutionCue($folded, $tokens);
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, true>  $surname
     * @param  array<string, true>  $given
     */
    private function hasVietnamesePerson(array $tokens, array $surname, array $given): bool
    {
        $count = count($tokens);
        for ($i = 0; $i < $count - 1; $i++) {
            $current = $tokens[$i];
            $next = $tokens[$i + 1];
            if (isset($surname[$current], $surname[$next]) && strlen($current) >= 4 && strlen($next) >= 4) {
                return true;
            }
            if (isset($surname[$current]) && strlen($current) >= 4 && isset($given[$next])) {
                return true;
            }
            if (isset($surname[$current]) && in_array($next, ['van', 'thi'], true)) {
                $after = $tokens[$i + 2] ?? '';
                if ($after !== '' && strlen($after) >= 2 && ! $this->isGenericToken($after)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function hasForeignPerson(array $tokens): bool
    {
        $given = array_fill_keys(self::WESTERN_GIVEN, true);
        $family = array_fill_keys(self::WESTERN_FAMILY, true);
        $surname = array_fill_keys(self::SURNAMES, true);
        $brands = array_fill_keys(self::BRANDS, true);
        $count = count($tokens);
        for ($i = 0; $i < $count - 1; $i++) {
            $current = $tokens[$i];
            $next = $tokens[$i + 1];
            if (isset($brands[$current]) || isset($brands[$next])) {
                continue;
            }
            if (isset($given[$current], $family[$next]) || isset($given[$current], $surname[$next]) || isset($surname[$current], $given[$next])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function hasInstitutionCue(string $folded, array $tokens): bool
    {
        $cues = ['anh van', 'anh ngu', 'trung tam', 'hoc vien', 'mam non', 'tieu hoc', 'dai hoc'];
        $hasCue = false;
        foreach ($cues as $cue) {
            if (str_contains($folded, $cue)) {
                $hasCue = true;
                break;
            }
        }
        if (! $hasCue) {
            return false;
        }

        $evidence = array_fill_keys([...self::ORG_TOKENS, ...self::COUNTRIES], true);
        foreach ($tokens as $token) {
            if (isset($evidence[$token])) {
                return true;
            }
        }

        return false;
    }

    private function isGenericToken(string $token): bool
    {
        return in_array($token, [
            'hoc', 'sinh', 'nu', 'nam', 'tre', 'em', 'be', 'dep', 'tot', 'moi', 'cap', 'cho', 'cua',
            'va', 'voi', 'tai', 'cac', 'loai', 'nao', 'nhat', 're', 'gia', 'cao', 'lon', 'nho',
            'den', 'trang', 'xanh', 'vang', 'hong', 'chong', 'nuoc', 'laptop', 'size', 'inch',
            'qua', 'tang', 'thoi', 'balo', 'tui', 'xach', 'mini', 'pro', 'max', 'air',
        ], true) || isset(array_fill_keys(self::BRANDS, true)[$token]);
    }
}
