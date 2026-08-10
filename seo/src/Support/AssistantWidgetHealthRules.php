<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Shared assistant dock health rules (PHP mirror of JS assistantWidgetHealth).
 */
final class AssistantWidgetHealthRules
{
    public const MIN_VALID_HTTP_LINKS = 5;

    public static function isValidHttpLinkHref(string $href): bool
    {
        $value = trim($href);
        if ($value === '' || str_starts_with($value, '#') || str_starts_with($value, 'javascript:')) {
            return false;
        }

        if (preg_match('/^(tel|mailto|sms|fax|callto|geo|skype|whatsapp|viber|zalo|maps):/i', $value) === 1) {
            return false;
        }

        return (bool) preg_match('#^(https?:)?//#i', $value) || str_starts_with($value, '/');
    }

    /**
     * @param  array{internal?: list<array{href?: string}>, external?: list<array{href?: string}>}  $extractedLinks
     */
    public static function countValidHttpLinks(array $extractedLinks): int
    {
        $buckets = array_merge(
            is_array($extractedLinks['internal'] ?? null) ? $extractedLinks['internal'] : [],
            is_array($extractedLinks['external'] ?? null) ? $extractedLinks['external'] : [],
        );

        $seen = [];
        $count = 0;
        foreach ($buckets as $link) {
            $href = trim((string) ($link['href'] ?? ''));
            if (! self::isValidHttpLinkHref($href)) {
                continue;
            }
            $key = rtrim(mb_strtolower($href, 'UTF-8'), '/');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $count++;
        }

        return $count;
    }

    /**
     * @param  array{internal?: list<array{href?: string}>, external?: list<array{href?: string}>}  $extractedLinks
     * @return array{key: string, item_count: int, issue_count: int, status: string, reasons: list<array{code: string, message: string}>}
     */
    public static function buildLinksHealth(array $extractedLinks, string $locale = 'vi'): array
    {
        $validCount = self::countValidHttpLinks($extractedLinks);
        $reasons = [];

        if ($validCount < self::MIN_VALID_HTTP_LINKS) {
            $reasons[] = [
                'code' => 'links_below_minimum',
                'message' => str_starts_with($locale, 'en')
                    ? 'Need at least '.self::MIN_VALID_HTTP_LINKS.' valid links ('.$validCount.'/'.self::MIN_VALID_HTTP_LINKS.').'
                    : 'Cần tối thiểu '.self::MIN_VALID_HTTP_LINKS.' link hợp lệ ('.$validCount.'/'.self::MIN_VALID_HTTP_LINKS.').',
            ];
        }

        return [
            'key' => 'links',
            'item_count' => $validCount,
            'issue_count' => count($reasons),
            'status' => $reasons !== [] ? 'error' : ($validCount > 0 ? 'success' : 'neutral'),
            'reasons' => $reasons,
        ];
    }

    /**
     * @return array{key: string, item_count: int, issue_count: int, status: string, reasons: list<array{code: string, message: string}>}
     */
    public static function buildSeoFocusKeywordHealth(?string $focusKeyword, string $locale = 'vi'): array
    {
        $keyword = trim((string) $focusKeyword);
        $reasons = [];

        if ($keyword === '' || preg_match('/^(từ khóa|keyword|focus keyword|nhập|enter)/iu', $keyword) === 1) {
            $reasons[] = [
                'code' => 'focus_keyword_missing',
                'message' => str_starts_with($locale, 'en')
                    ? 'Focus keyword is missing'
                    : 'Thiếu từ khóa chính',
            ];
        }

        return [
            'key' => 'seo',
            'item_count' => 0,
            'issue_count' => count($reasons),
            'status' => $reasons !== [] ? 'error' : 'success',
            'reasons' => $reasons,
        ];
    }
}
