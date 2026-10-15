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
    private const TEMPLATE_STEMS = [
        'qua tang', 'quang cao', 'anh van', 'anh ngu', 'ngoai ngu', 'mam non', 'trung tam',
        'benh vien', 'ngan hang', 'tu thien', 'cong doan', 'dai hoc', 'tieu hoc',
    ];

    /** @var list<string> */
    private const PRODUCT_ATTRIBUTES = [
        'mau', 'den', 'trang', 'xanh', 'do', 'vang', 'hong', 'nau', 'xam', 'tim', 'be',
        'nam', 'nu', 'lon', 'nho', 'mini', 'size', 'inch', 'laptop', 'hoc', 'sinh', 'tre', 'em',
        'dep', 'gia', 're', 'cao', 'cap', 'vai', 'bo', 'nhua', 'canvas', 'chong', 'nuoc',
        'the', 'thao', 'lich', 'cong', 'nghe', 'su', 'kien', 'doanh', 'nghiep', 'te', 'tai',
        'chinh', 'logo', 'theu', 'chat', 'luong', 'qua', 'tang', 'thoi', 'balo', 'tui', 'xach',
        'cap', 'sach', 'dong', 'phuc', 'mau', 'non', 'anh', 'van', 'ngu', 'ngoai',
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
            $normalized = $this->comparisonKey(trim($normalized));
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
    public function classify(
        string $raw,
        bool $isDuplicateVariant = false,
        bool $isTemplateName = false,
        bool $isTruncatedCopy = false,
        bool $isContainedCopy = false,
    ): array {
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

        if ($this->isMalformed($raw) || $isTruncatedCopy) {
            $codes[] = KeywordQualityCode::MALFORMED_TEXT;
        }

        if ($this->isSentenceLike($raw, $folded) || $isContainedCopy) {
            $codes[] = KeywordQualityCode::SENTENCE_LIKE;
        }

        if ($this->isSuspectProperName($folded) || $isTemplateName) {
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
     * Customer/org titles that repeat a site stem and end in a rare name.
     * Product attributes and known product brands are not names.
     *
     * @param  array<int, string>  $rawById
     * @return list<int>
     */
    public function templateNameIds(array $rawById): array
    {
        $rows = [];
        $documentFrequency = [];
        foreach ($rawById as $id => $raw) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $folded = $this->normalizer->fold($this->normalized((string) $raw));
            $tokens = preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens === []) {
                continue;
            }
            $rows[] = ['id' => $id, 'raw' => (string) $raw, 'folded' => $folded, 'tokens' => $tokens];
            foreach (array_unique($tokens) as $token) {
                $documentFrequency[$token] = ($documentFrequency[$token] ?? 0) + 1;
            }
        }

        $stemCounts = [];
        foreach ($rows as $row) {
            $stem = $this->matchingTemplateStem($row['folded']);
            if ($stem === null) {
                continue;
            }
            $stemCounts[$stem] = ($stemCounts[$stem] ?? 0) + 1;
        }

        $hits = [];
        foreach ($rows as $row) {
            $stem = $this->matchingTemplateStem($row['folded']);
            if ($stem === null || ($stemCounts[$stem] ?? 0) < 3) {
                continue;
            }
            $suffix = $this->tokensAfterStem($row['tokens'], $stem);
            if ($suffix === [] || count($suffix) > 6) {
                continue;
            }
            if (! $this->suffixHasRareName($suffix, $documentFrequency)) {
                continue;
            }
            $hits[] = $row['id'];
        }

        return $hits;
    }

    /**
     * Copy missing the first one or two characters of another keyword on the same site.
     *
     * @param  array<int, string>  $normalizedById
     * @return list<int>
     */
    public function truncatedCopyIds(array $normalizedById): array
    {
        $byText = [];
        foreach ($normalizedById as $id => $normalized) {
            $text = $this->comparisonKey(trim($normalized));
            if ($text === '' || (int) $id <= 0) {
                continue;
            }
            $byText[$text][] = (int) $id;
        }

        $hits = [];
        foreach (array_keys($byText) as $text) {
            if (mb_strlen($text) < 18) {
                continue;
            }
            foreach ([1, 2] as $drop) {
                $short = mb_substr($text, $drop);
                if (! isset($byText[$short])) {
                    continue;
                }
                $tokens = preg_split('/\s+/u', $short, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (count($tokens) < 4) {
                    continue;
                }
                foreach ($byText[$short] as $shortId) {
                    $hits[$shortId] = true;
                }
            }
        }

        return array_keys($hits);
    }

    /**
     * Site-relative prefix/suffix extensions and repeated non-attribute name pairs.
     *
     * @param  array<int, string>  $rawById
     * @return list<int>
     */
    public function patternExtensionIds(array $rawById): array
    {
        $rows = $this->phraseRows($rawById);
        if ($rows === []) {
            return [];
        }

        $documentFrequency = [];
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['folded']][] = $row['id'];
            foreach (array_unique($row['tokens']) as $token) {
                $documentFrequency[$token] = ($documentFrequency[$token] ?? 0) + 1;
            }
        }

        $bigramCounts = [];
        $prefixCounts = [];
        foreach ($rows as $row) {
            $tokens = $row['tokens'];
            $count = count($tokens);
            $seen = [];
            for ($i = 0; $i < $count - 1; $i++) {
                if (! $this->isRepeatedNameToken($tokens[$i]) || ! $this->isRepeatedNameToken($tokens[$i + 1])) {
                    continue;
                }
                if (! $this->tokenIsCapitalized($row['raw'], $tokens[$i]) || ! $this->tokenIsCapitalized($row['raw'], $tokens[$i + 1])) {
                    continue;
                }
                $bigram = $tokens[$i].' '.$tokens[$i + 1];
                if (isset($seen[$bigram])) {
                    continue;
                }
                $seen[$bigram] = true;
                $bigramCounts[$bigram] = ($bigramCounts[$bigram] ?? 0) + 1;
            }
            foreach ([2, 3] as $length) {
                if ($count <= $length) {
                    continue;
                }
                $prefix = implode(' ', array_slice($tokens, 0, $length));
                $prefixCounts[$prefix] = ($prefixCounts[$prefix] ?? 0) + 1;
            }
        }

        $hits = [];
        foreach ($rows as $row) {
            if ($this->isSearchQuestion($row['raw'], $row['folded'])) {
                continue;
            }
            $tokens = $row['tokens'];
            $count = count($tokens);
            if ($count >= 4) {
                for ($i = 0; $i < $count - 1; $i++) {
                    $bigram = $tokens[$i].' '.$tokens[$i + 1];
                    if (($bigramCounts[$bigram] ?? 0) >= 3) {
                        $hits[$row['id']] = true;
                        break;
                    }
                }
            }
            foreach ([2, 3] as $length) {
                if ($count <= $length) {
                    continue;
                }
                $prefix = implode(' ', array_slice($tokens, 0, $length));
                if (($prefixCounts[$prefix] ?? 0) < 3) {
                    continue;
                }
                if ($this->extrasLookSuspicious(array_slice($tokens, $length), $documentFrequency)) {
                    $hits[$row['id']] = true;
                }
            }
            for ($base = 2; $base < $count; $base++) {
                $head = implode(' ', array_slice($tokens, 0, $base));
                $tail = implode(' ', array_slice($tokens, $count - $base));
                if (isset($byKey[$head]) && $this->extrasLookSuspicious(array_slice($tokens, $base), $documentFrequency)) {
                    $hits[$row['id']] = true;
                }
                if ($tail !== $head && isset($byKey[$tail]) && $this->extrasLookSuspicious(array_slice($tokens, 0, $count - $base), $documentFrequency)) {
                    $hits[$row['id']] = true;
                }
            }
        }

        return array_keys($hits);
    }

    /**
     * Longer keyword that pastes another keyword and adds a clause or rare token.
     *
     * @param  array<int, string>  $rawById
     * @return list<int>
     */
    public function containedCopyIds(array $rawById): array
    {
        $rows = [];
        foreach ($rawById as $id => $raw) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $folded = $this->normalizer->fold($this->normalized((string) $raw));
            $text = $this->comparisonKey($folded);
            $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens === []) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'raw' => (string) $raw,
                'folded' => $folded,
                'text' => $text,
                'tokens' => $tokens,
            ];
        }

        $shorts = [];
        foreach ($rows as $row) {
            if (count($row['tokens']) < 3 || mb_strlen($row['text']) < 12) {
                continue;
            }
            $shorts[$row['text']] = true;
        }

        $hits = [];
        foreach ($rows as $row) {
            if (count($row['tokens']) < 5 || $this->isSearchQuestion($row['raw'], $row['folded'])) {
                continue;
            }
            foreach (array_keys($shorts) as $short) {
                if ($short === $row['text'] || mb_strlen($short) >= mb_strlen($row['text'])) {
                    continue;
                }
                if (! str_contains(' '.$row['text'].' ', ' '.$short.' ')) {
                    continue;
                }
                $rest = trim(str_replace($short, ' ', $row['text']));
                $extra = preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($extra === []) {
                    continue;
                }
                if ($this->hasClauseParticle($rest) || count($extra) >= 6) {
                    $hits[$row['id']] = true;
                    break;
                }
            }
        }

        return array_keys($hits);
    }

    private function comparisonKey(string $normalized): string
    {
        $key = preg_replace('/[.\'’"“”\-–—\/\\\\]+/u', ' ', $normalized) ?? $normalized;
        $key = preg_replace('/\s+/u', ' ', trim($key)) ?? trim($key);

        return trim($key);
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
            if ($tokenCount === 0) {
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

        if (preg_match('/[|_\[\]{}\\\\]|--|__/u', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/[.!…]["\']?\s*$/u', $trimmed) === 1) {
            $tokens = preg_split('/\s+/u', trim($trimmed, " \t.!…\"'"), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens !== [] && count($tokens) <= 4) {
                return true;
            }
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
        $count = count($tokens);
        if ($count < 5) {
            return false;
        }

        $hasClause = preg_match('/\p{L}{3,},\s+\p{L}/u', $raw) === 1;
        $endsSentence = preg_match('/[.!…]["\']?\s*$/u', trim($raw)) === 1;
        $hasTitleClause = preg_match('/\p{L}\s+[-–—]\s+\p{L}/u', $raw) === 1;
        $hasColon = preg_match('/\p{L}{3,}:\s+\p{L}/u', $raw) === 1;
        if ($hasClause || $endsSentence || $hasTitleClause || $hasColon) {
            return true;
        }

        $hasStrongFiller = false;
        foreach (['khong chi', 'su lua chon', 'trai nghiem', 'khang dinh', 'duoc thiet ke', 'se giup', 'danh cho'] as $filler) {
            if (str_contains($folded, $filler)) {
                $hasStrongFiller = true;
                break;
            }
        }
        if ($hasStrongFiller && $count >= 6) {
            return true;
        }

        $hasFiller = false;
        foreach (self::SENTENCE_FILLERS as $filler) {
            if (str_contains($folded, $filler)) {
                $hasFiller = true;
                break;
            }
        }
        if ($hasFiller && $count >= 7) {
            return true;
        }

        return $this->hasClauseParticle($folded) && $count >= 9;
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
            'tuoi', 'cap', 'lop', 'quan',
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
            || str_contains($folded, ' nhu the nao')
            || str_contains($folded, ' la gi')
            || str_contains($folded, ' nao ')
            || str_starts_with($folded, 'huong dan')
            || str_starts_with($folded, 'nen ');
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

        return $this->hasInstitutionCue($folded, $tokens) || $this->hasNamedOrganization($tokens);
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
            if (isset($surname[$current]) && strlen($current) >= 4 && isset($given[$next]) && strlen($next) >= 4) {
                return true;
            }
            if (isset($given[$current]) && strlen($current) >= 4 && isset($surname[$next]) && strlen($next) >= 4) {
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

    /**
     * @param  list<string>  $tokens
     */
    private function hasNamedOrganization(array $tokens): bool
    {
        $cues = ['truong', 'mam', 'non', 'trung', 'tam', 'benh', 'vien', 'ngan', 'hang', 'dai', 'hoc', 'thcs', 'thpt', 'tieu'];
        $brands = array_fill_keys(self::BRANDS, true);
        $attributes = array_fill_keys(self::PRODUCT_ATTRIBUTES, true);
        $cueTokens = array_fill_keys($cues, true);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $cue = $this->organizationCueAt($tokens, $i);
            if ($cue === 0) {
                continue;
            }
            for ($j = $i + $cue; $j < $count; $j++) {
                $token = $tokens[$j];
                if (isset($brands[$token]) || isset($attributes[$token]) || isset($cueTokens[$token]) || preg_match('/^\d+$/', $token) === 1) {
                    continue;
                }
                if (strlen($token) >= 3 && ! $this->isGenericToken($token)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function organizationCueAt(array $tokens, int $index): int
    {
        $pairs = [
            ['mam', 'non'],
            ['trung', 'tam'],
            ['benh', 'vien'],
            ['ngan', 'hang'],
            ['dai', 'hoc'],
            ['tieu', 'hoc'],
            ['truong', 'hoc'],
        ];
        $current = $tokens[$index];
        $next = $tokens[$index + 1] ?? '';
        foreach ($pairs as $pair) {
            if ($current === $pair[0] && $next === $pair[1]) {
                return 2;
            }
        }
        if (in_array($current, ['thcs', 'thpt'], true)) {
            return 1;
        }

        return 0;
    }

    private function matchingTemplateStem(string $folded): ?string
    {
        foreach (self::TEMPLATE_STEMS as $stem) {
            if (preg_match('/(?:^|\s)'.preg_quote($stem, '/').'(?:\s|$)/u', $folded) === 1) {
                return $stem;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function tokensAfterStem(array $tokens, string $stem): array
    {
        $stemTokens = preg_split('/\s+/u', $stem, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($tokens);
        $stemCount = count($stemTokens);
        for ($i = 0; $i <= $count - $stemCount; $i++) {
            if (array_slice($tokens, $i, $stemCount) === $stemTokens) {
                return array_slice($tokens, $i + $stemCount);
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $suffix
     * @param  array<string, int>  $documentFrequency
     */
    private function suffixHasRareName(array $suffix, array $documentFrequency): bool
    {
        $attributes = array_fill_keys(self::PRODUCT_ATTRIBUTES, true);
        $brands = array_fill_keys(self::BRANDS, true);
        foreach ($suffix as $token) {
            if (isset($attributes[$token]) || isset($brands[$token]) || preg_match('/^\d{1,4}$/', $token) === 1) {
                continue;
            }
            if (strlen($token) < 3 || ($documentFrequency[$token] ?? 0) > 5) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<int, string>  $rawById
     * @return list<array{id: int, raw: string, folded: string, tokens: list<string>}>
     */
    private function phraseRows(array $rawById): array
    {
        $rows = [];
        foreach ($rawById as $id => $raw) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $folded = $this->normalizer->fold($this->normalized((string) $raw));
            $tokens = preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens === []) {
                continue;
            }
            $rows[] = ['id' => $id, 'raw' => (string) $raw, 'folded' => $folded, 'tokens' => $tokens];
        }

        return $rows;
    }

    private function isRepeatedNameToken(string $token): bool
    {
        if (strlen($token) < 3 || preg_match('/^\d+$/', $token) === 1) {
            return false;
        }
        if (in_array($token, [
            'san', 'pham', 'loai', 'dang', 'dung', 'theo', 'hang', 'cong', 'ty', 'thuong', 'hieu',
            'phoi', 'gioi', 'tinh', 'muc', 'dich', 'danh', 'nhung', 'nguoi', 'trong', 'ngoai',
            'tren', 'duoi', 'duoc', 'khong', 'nhieu', 'nhat', 'mau', 'sac',
        ], true)) {
            return false;
        }

        $attributes = array_fill_keys(self::PRODUCT_ATTRIBUTES, true);
        $brands = array_fill_keys(self::BRANDS, true);

        return ! isset($attributes[$token]) && ! isset($brands[$token]) && ! $this->isGenericToken($token);
    }

    /**
     * @param  list<string>  $extra
     * @param  array<string, int>  $documentFrequency
     */
    private function extrasLookSuspicious(array $extra, array $documentFrequency): bool
    {
        foreach ($extra as $token) {
            if (! $this->isRepeatedNameToken($token)) {
                continue;
            }
            if (($documentFrequency[$token] ?? 0) <= 4) {
                return true;
            }
        }

        return false;
    }

    private function tokenIsCapitalized(string $raw, string $foldedToken): bool
    {
        $parts = preg_split('/\s+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            $folded = $this->normalizer->fold($this->normalizer->normalize($part)['normalized_text']);
            if ($folded !== $foldedToken) {
                continue;
            }
            $letter = preg_replace('/[^\p{L}].*/u', '', $part) ?? '';

            return $letter !== '' && preg_match('/^\p{Lu}/u', $letter) === 1;
        }

        return false;
    }

    private function hasClauseParticle(string $folded): bool
    {
        foreach ([' cho ', ' cua ', ' voi ', ' trong ', ' moi ', ' nhung ', ' khi ', ' ve ', ' cung ', ' de ', ' danh '] as $particle) {
            if (str_contains(' '.$folded.' ', $particle)) {
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
