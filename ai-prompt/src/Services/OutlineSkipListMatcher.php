<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Skip List cho dò trùng outline — hỗ trợ wildcard SQL (%).
 *
 * Lớp 1 (PHP): so khớp wildcard UTF-8, không phân biệt hoa thường.
 * Lớp 2 (SQL): NOT LIKE — loại heading cũ trong DB khỏi kết quả FTS/exact.
 */
final class OutlineSkipListMatcher
{
    /**
     * Chuẩn hóa pattern: nếu không có % thì bọc %...% (contains).
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public function normalizeSqlPatterns(array $patterns): array
    {
        $normalized = [];

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            $normalized[] = str_contains($pattern, '%')
                ? $pattern
                : '%'.$pattern.'%';
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Lớp 1: heading đang xét có khớp skip pattern không (case-insensitive).
     *
     * So khớp trên cả 2 biến thể: text gốc và text đã bỏ tiền tố đánh số
     * ("3. So sánh..." -> "so sánh...") để pattern dạng 'so sánh%' vẫn bắt được
     * heading có số thứ tự ở đầu.
     *
     * @param  list<string>  $sqlPatterns  đã qua normalizeSqlPatterns()
     */
    public function isSkipped(string $headingText, array $sqlPatterns): bool
    {
        if ($sqlPatterns === []) {
            return false;
        }

        $headingLower = mb_strtolower(trim($headingText), 'UTF-8');
        if ($headingLower === '') {
            return false;
        }

        $candidates = array_unique([
            $headingLower,
            $this->stripLeadingNoise($headingLower),
        ]);

        foreach ($sqlPatterns as $pattern) {
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && $this->matchesSqlLikePattern($candidate, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * So khớp SQL LIKE (%/_), không phân biệt hoa thường, hỗ trợ UTF-8.
     */
    public function matchesSqlLikePattern(string $text, string $sqlPattern): bool
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $pattern = mb_strtolower(trim($sqlPattern), 'UTF-8');

        if ($text === '' || $pattern === '') {
            return false;
        }

        $startsWithWildcard = str_starts_with($pattern, '%');
        $endsWithWildcard = str_ends_with($pattern, '%');

        if ($startsWithWildcard && $endsWithWildcard) {
            $needle = trim($pattern, '%');

            return $needle !== '' && mb_strpos($text, $needle) !== false;
        }

        if ($endsWithWildcard && ! $startsWithWildcard) {
            $prefix = mb_substr($pattern, 0, mb_strlen($pattern) - 1);

            return $prefix === '' || mb_strpos($text, $prefix) === 0;
        }

        if ($startsWithWildcard && ! $endsWithWildcard) {
            $suffix = mb_substr($pattern, 1);
            if ($suffix === '') {
                return true;
            }

            $suffixLength = mb_strlen($suffix);
            if (mb_strlen($text) < $suffixLength) {
                return false;
            }

            return mb_substr($text, -$suffixLength) === $suffix;
        }

        return $text === $pattern;
    }

    /**
     * Bỏ tiền tố đánh số / ký tự trang trí ở đầu heading (mọi ký tự không phải chữ):
     * "3. So sánh" -> "So sánh", "1.2 Chất liệu" -> "Chất liệu", "🔥 So sánh" -> "So sánh".
     */
    public function stripLeadingNoise(string $text): string
    {
        $stripped = preg_replace('/^[^\p{L}]+/u', '', $text);

        return trim($stripped ?? $text);
    }

    /**
     * Lớp 2: loại heading trong DB thuộc skip list (NOT LIKE từng pattern).
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $sqlPatterns
     */
    public function applyNotLikeFilters(Builder $query, array $sqlPatterns, string $column = 'heading_text'): Builder
    {
        foreach ($sqlPatterns as $pattern) {
            $query->where($column, 'NOT LIKE', $pattern);
        }

        return $query;
    }
}
