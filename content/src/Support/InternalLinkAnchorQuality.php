<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\SearchFoundation\Support\KeywordPhraseMatcher;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;

/**
 * Deterministic SEO/link-anchor quality for Advanced content_deep.
 *
 * Separate from destination relevance: a title hit on a generic phrase
 * ("Lý do" → "Lý do chọn…") must not become a final Internal Link suggestion.
 *
 * Heuristics + a small abstract seed — not a giant stopword dump.
 */
final class InternalLinkAnchorQuality
{
    public const MIN_ACCEPT = 50;

    public const REASON_GENERIC_ABSTRACT = 'generic_abstract';

    public const REASON_LOW_INFORMATION = 'low_information';

    public const REASON_ENTITY_PHRASE = 'entity_phrase';

    public const REASON_MATERIAL_CODE = 'material_code';

    public const REASON_INVENTORY_KEYWORD = 'inventory_keyword';

    public const REASON_HEADING_STRONG = 'heading_or_strong';

    /**
     * @param  array{
     *     source?: string,
     *     is_inventory_keyword?: bool
     * }  $context
     * @return array{score: int, reason: string, accepted: bool}
     */
    public static function evaluate(string $phrase, array $context = []): array
    {
        $norm = KeywordPhraseMatcher::normalize($phrase);
        if ($norm === '') {
            return [
                'score' => 0,
                'reason' => self::REASON_LOW_INFORMATION,
                'accepted' => false,
            ];
        }

        if (self::isAbstractPhrase($norm)) {
            return [
                'score' => 10,
                'reason' => self::REASON_GENERIC_ABSTRACT,
                'accepted' => false,
            ];
        }

        $tokens = preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordCount = count($tokens);
        if ($wordCount === 0) {
            return [
                'score' => 0,
                'reason' => self::REASON_LOW_INFORMATION,
                'accepted' => false,
            ];
        }

        $score = 28;
        $reason = self::REASON_LOW_INFORMATION;

        if (self::hasMaterialOrGradeCode($norm)) {
            $score += 35;
            $reason = self::REASON_MATERIAL_CODE;
        }

        $entityTokens = self::entityTokens($tokens);
        $genericCount = 0;
        foreach ($tokens as $token) {
            if (self::isGenericToken($token)) {
                $genericCount++;
            }
        }

        if ($entityTokens !== []) {
            $score += 18 + min(12, count($entityTokens) * 4);
            $reason = self::REASON_ENTITY_PHRASE;
        }

        if ($wordCount >= 2 && $entityTokens !== []) {
            $score += 10;
        }

        if ($wordCount === 1 && $entityTokens === [] && ! self::hasMaterialOrGradeCode($norm)) {
            $score -= 20;
            $reason = self::REASON_LOW_INFORMATION;
        }

        if ($wordCount > 0 && ($genericCount / $wordCount) >= 0.66 && $entityTokens === []) {
            $score -= 25;
            $reason = self::REASON_GENERIC_ABSTRACT;
        }

        if (($context['is_inventory_keyword'] ?? false) === true) {
            $score += 22;
            $reason = self::REASON_INVENTORY_KEYWORD;
        }

        $source = (string) ($context['source'] ?? '');
        if (in_array($source, ['strong_window', 'highlight', 'keyword', 'focus_keyword'], true)) {
            $score += 10;
            if ($reason === self::REASON_LOW_INFORMATION) {
                $reason = self::REASON_HEADING_STRONG;
            }
        } elseif ($source === 'heading') {
            $score += 4;
        }

        $score = LinkSuggestionScoreScale::clamp($score);

        return [
            'score' => $score,
            'reason' => $reason,
            'accepted' => $score >= self::MIN_ACCEPT,
        ];
    }

    private static function isAbstractPhrase(string $norm): bool
    {
        foreach (self::abstractPhraseSeed() as $seed) {
            if ($norm === $seed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Small seed of abstract / low-value anchors — examples, not an exhaustive dump.
     *
     * @return list<string>
     */
    private static function abstractPhraseSeed(): array
    {
        // Normalized forms (lowercase, punctuation stripped) — keep diacritics as KeywordPhraseMatcher does.
        $raw = [
            'lý do',
            'nổi bật',
            'đặc điểm',
            'thông tin',
            'lợi ích',
            'sử dụng',
            'phù hợp',
            'giúp',
            'có thể',
            'cách',
            'tại sao',
            'như thế nào',
            'bao gồm',
            'nói chung',
            'tóm tắt',
            'giới thiệu',
            'kết luận',
            'tìm hiểu',
            'xem thêm',
            'chi tiết',
            'quan trọng',
            'tốt nhất',
            'hàng đầu',
            // ASCII-folded mirrors for content that lost diacritics
            'ly do',
            'noi bat',
            'dac diem',
            'thong tin',
            'loi ich',
            'su dung',
            'phu hop',
            'giup',
            'co the',
        ];

        $out = [];
        foreach ($raw as $phrase) {
            $norm = KeywordPhraseMatcher::normalize($phrase);
            if ($norm !== '') {
                $out[] = $norm;
            }
        }

        return array_values(array_unique($out));
    }

    private static function isGenericToken(string $token): bool
    {
        $token = KeywordPhraseMatcher::normalize($token);
        if ($token === '') {
            return true;
        }

        static $set = null;
        if ($set === null) {
            $raw = [
                'lý', 'do', 'nổi', 'bật', 'đặc', 'điểm', 'thông', 'tin', 'lợi', 'ích',
                'sử', 'dụng', 'phù', 'hợp', 'giúp', 'có', 'thể', 'cách', 'rất', 'nhiều',
                'các', 'những', 'này', 'kia', 'là', 'và', 'của', 'cho', 'trong', 'với',
                'khi', 'nếu', 'để', 'từ', 'một', 'hai', 'hay', 'hoặc', 'nhất', 'hơn',
                'được', 'bị', 'sẽ', 'đang', 'đã', 'vẫn', 'cũng', 'như', 'về', 'tại',
                'bao', 'gồm', 'tóm', 'tắt', 'giới', 'thiệu', 'kết', 'luận',
                'ly', 'noi', 'bat', 'dac', 'diem', 'thong', 'loi', 'ich', 'su', 'dung',
                'phu', 'hop', 'giup', 'co', 'the', 'rat', 'nhieu', 'cac', 'nhung', 'nay',
                'la', 'va', 'cua', 'voi', 'neu', 'de', 'tu', 'mot', 'hoac', 'nhat', 'hon',
                'duoc', 'bi', 'se', 'dang', 'da', 'van', 'cung', 'nhu', 've', 'tai',
                'gom', 'tom', 'tat', 'gioi', 'thieu', 'ket', 'luan',
            ];
            $set = [];
            foreach ($raw as $item) {
                $norm = KeywordPhraseMatcher::normalize($item);
                if ($norm !== '') {
                    $set[$norm] = true;
                }
            }
        }

        return isset($set[$token]);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private static function entityTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (self::isGenericToken($token)) {
                continue;
            }
            $len = mb_strlen($token);
            if ($len >= 4 || preg_match('/\d/u', $token) === 1) {
                $out[] = $token;
            }
        }

        return $out;
    }

    private static function hasMaterialOrGradeCode(string $norm): bool
    {
        // 600D / 1680D / polyester 600 d style material grades
        if (preg_match('/\b\d{2,4}\s*d\b/u', $norm) === 1) {
            return true;
        }

        if (preg_match('/\b(?:polyester|nylon|canvas|oxford|pu|pvc|cotton|jute)\b/u', $norm) === 1) {
            return true;
        }

        return false;
    }
}
