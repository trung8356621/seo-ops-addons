<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Loại bỏ byte UTF-8 lỗi — tránh json_encode / Http::post báo "Malformed UTF-8".
 *
 * Variable bags may be array<string, mixed> (scalars + nested side-channels like
 * product_gallery / quick_split). Nested AI strings use the same compact semantics
 * as top-level variablesForAi values. arrayDeep() is UTF-8-only (no AI compaction).
 */
final class Utf8Sanitizer
{
    public static function string(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            } else {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        }

        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = is_string($cleaned) ? $cleaned : $value;

        // mb_check_encoding có thể pass trong khi json_encode (Http::post) vẫn báo Malformed UTF-8.
        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded !== false) {
            $decoded = json_decode($encoded);
            if (is_string($decoded)) {
                $value = $decoded;
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function variables(array $variables): array
    {
        $normalized = [];

        foreach ($variables as $key => $value) {
            $normalized[(string) $key] = self::sanitizeVariableValue($value);
        }

        return $normalized;
    }

    /**
     * Chuẩn hóa biến trước khi gửi AI: trim + gộp khoảng trắng dư nhưng vẫn giữ ý theo đoạn.
     * Nested array (product_gallery, quick_split, …) giữ structure — không cast (string).
     * Nested strings dùng cùng compactForAiVariable như top-level.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function variablesForAi(array $variables): array
    {
        $normalized = [];

        foreach ($variables as $key => $value) {
            $normalized[(string) $key] = self::sanitizeAiVariableValue($value);
        }

        return $normalized;
    }

    /**
     * Nén khoảng trắng theo hướng tiết kiệm token:
     * - trim từng dòng
     * - gộp tab/khoảng trắng liên tiếp trong dòng
     * - chuẩn hóa line break và rút gọn block dòng trống dài
     */
    public static function compactForAiVariable(string $value): string
    {
        $value = self::string($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = explode("\n", $value);
        $normalizedLines = [];

        foreach ($lines as $line) {
            $line = self::string($line);
            $line = (string) preg_replace('/[^\S\n]+/u', ' ', $line);
            $normalizedLines[] = trim($line);
        }

        $value = implode("\n", $normalizedLines);
        $value = (string) preg_replace("/\n{3,}/", "\n\n", $value);

        return trim($value);
    }

    /**
     * UTF-8 deep sanitize only (no AI whitespace compaction).
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function arrayDeep(array $payload): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            $normalized[$key] = self::sanitizeVariableValue($value);
        }

        return $normalized;
    }

    /**
     * Value-level sanitize for non-AI bags: UTF-8 string cleanup, preserve structure/types.
     */
    private static function sanitizeVariableValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::string($value);
        }

        if (is_array($value)) {
            $nested = [];
            foreach ($value as $nestedKey => $nestedValue) {
                $nested[$nestedKey] = self::sanitizeVariableValue($nestedValue);
            }

            return $nested;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return self::string((string) $value);
        }

        // object/resource outside contract: never silent (string) cast.
        return $value;
    }

    /**
     * Value-level sanitize for AI bags: same structure rules as sanitizeVariableValue,
     * but every string (top-level or nested) goes through compactForAiVariable().
     */
    private static function sanitizeAiVariableValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::compactForAiVariable($value);
        }

        if (is_array($value)) {
            $nested = [];
            foreach ($value as $nestedKey => $nestedValue) {
                $nested[$nestedKey] = self::sanitizeAiVariableValue($nestedValue);
            }

            return $nested;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return self::compactForAiVariable((string) $value);
        }

        // object/resource outside contract: never silent (string) cast.
        return $value;
    }
}
