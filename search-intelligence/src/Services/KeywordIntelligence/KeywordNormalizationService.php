<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordNormalizationResult;

/**
 * Normalize keyword for matching — keep original display separately.
 * Không bỏ dấu tiếng Việt, không stem, không sửa chính tả.
 */
final class KeywordNormalizationService
{
    private const DEFAULT_MAX_LENGTH = 500;

    /**
     * Phân tích + validate 1 keyword thô — dùng cho preview import/UI review.
     * Không thay đổi hành vi của normalize()/displayKeyword() hiện có.
     */
    public function analyze(string $keyword): KeywordNormalizationResult
    {
        $original = $keyword;
        $trimmedOriginal = trim($keyword);
        $normalized = $this->normalize($keyword);
        $displayValue = $this->displayKeyword($keyword);

        $changes = [];
        if ($trimmedOriginal !== $keyword) {
            $changes[] = 'trimmed_whitespace';
        }
        if ($trimmedOriginal !== '' && $this->stripEdgePunctuation($trimmedOriginal) !== $trimmedOriginal) {
            $changes[] = 'stripped_edge_punctuation';
        }
        if (preg_replace('/\s+/u', ' ', $trimmedOriginal) !== $trimmedOriginal) {
            $changes[] = 'collapsed_whitespace';
        }
        if ($displayValue !== '' && mb_strtolower($displayValue, 'UTF-8') !== $displayValue) {
            $changes[] = 'lowercased';
        }

        $maxLength = $this->maxKeywordLength();
        $warnings = [];
        $failureCode = null;

        if ($normalized === '') {
            $failureCode = 'keyword.empty';
        } elseif (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            $failureCode = 'keyword.too_long';
        } elseif (! preg_match('/[\p{L}\p{N}]/u', $normalized)) {
            // Không còn ký tự chữ/số nào sau normalize — chỉ toàn ký tự đặc biệt.
            $failureCode = 'keyword.invalid_chars';
        }

        $isValid = $failureCode === null;

        if ($isValid && $maxLength > 0 && mb_strlen($normalized, 'UTF-8') > (int) ($maxLength * 0.8)) {
            $warnings[] = 'keyword.near_length_limit';
        }

        return new KeywordNormalizationResult(
            original: $original,
            normalized: $normalized,
            displayValue: $displayValue,
            isValid: $isValid,
            changes: $changes,
            warnings: $warnings,
            failureCode: $failureCode,
        );
    }

    private function maxKeywordLength(): int
    {
        if (! function_exists('config')) {
            return self::DEFAULT_MAX_LENGTH;
        }

        try {
            return (int) config('seo-content-ai.keyword_intelligence.normalization.max_keyword_length', self::DEFAULT_MAX_LENGTH);
        } catch (\Throwable) {
            return self::DEFAULT_MAX_LENGTH;
        }
    }

    public function normalize(string $keyword): string
    {
        $value = trim($keyword);
        if ($value === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        // ASCII-only edge punctuation — avoid smart-quote class (FTP/encoding can break /u patterns).
        $value = $this->stripEdgePunctuation($value);

        return mb_strtolower($value, 'UTF-8');
    }

    public function displayKeyword(string $keyword): string
    {
        $value = trim($keyword);
        if ($value === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        $value = $this->stripEdgePunctuation($value);

        return trim($value);
    }

    private function stripEdgePunctuation(string $value): string
    {
        $value = preg_replace('/^[\s:;,.\\-_\"\']+/u', '', $value) ?? $value;
        $value = preg_replace('/[\s:;,.\\-_\"\']+$/u', '', $value) ?? $value;

        return trim($value);
    }

    /**
     * Near-duplicate heuristic — same tokens ignoring order? No: only high similarity ratio.
     * Does NOT merge different intents.
     */
    public function isNearDuplicate(string $aNormalized, string $bNormalized): bool
    {
        if ($aNormalized === '' || $bNormalized === '' || $aNormalized === $bNormalized) {
            return false;
        }

        similar_text($aNormalized, $bNormalized, $percent);
        if ($percent < 88.0) {
            return false;
        }

        $tokensA = preg_split('/\s+/u', $aNormalized) ?: [];
        $tokensB = preg_split('/\s+/u', $bNormalized) ?: [];
        if (count($tokensA) <= 1 || count($tokensB) <= 1) {
            return false;
        }

        // Block obvious different entities: "seo là gì" vs "dịch vụ seo"
        // Unicode escapes keep stop-words stable if source encoding is corrupted.
        $stop = [
            "l\u{00E0}", // là
            "g\u{00EC}", // gì
            'the', 'a', 'an', 'of', 'for', 'to',
            "v\u{00E0}", // và
            'cho',
            "t\u{1EA1}i", // tại
        ];
        $coreA = array_values(array_filter($tokensA, static fn (string $t): bool => ! in_array($t, $stop, true)));
        $coreB = array_values(array_filter($tokensB, static fn (string $t): bool => ! in_array($t, $stop, true)));

        if ($coreA === [] || $coreB === []) {
            return false;
        }

        sort($coreA);
        sort($coreB);

        return $coreA === $coreB;
    }
}
