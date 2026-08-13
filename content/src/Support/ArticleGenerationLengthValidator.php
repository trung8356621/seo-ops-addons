<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;

/**
 * Length contract cho full article generation (generate / legacy rewrite).
 *
 * target_article_length = {{article_length}} trong Prompt (mục tiêu AI — không hard-fail).
 * minimum_acceptable_words = floor(target × ratio) + 1 — ACCEPT khi actual >= minimum
 * (target 2000 + ratio 0.5 ⇒ minimum 1001 ⇒ >1000 words).
 *
 * Improve không dùng class này.
 */
final class ArticleGenerationLengthValidator
{
    public const DEFAULT_MINIMUM_ACCEPTABLE_RATIO = 0.5;

    /**
     * Fallback khi target ≤ 0 (schema thiếu article_length).
     */
    public const DEFAULT_ABSOLUTE_FLOOR_WHEN_NO_TARGET = 300;

    /**
     * @return array{
     *     actual_word_count: int,
     *     minimum_acceptable_words: int,
     *     target_article_length: int,
     *     length_validation_result: 'accepted'|'truncated'
     * }
     */
    public function evaluate(string $text, int $targetArticleLength): array
    {
        $target = max(0, $targetArticleLength);
        $actual = PromptTextMetrics::wordCount($text);
        $minimum = $this->minimumForTarget($target);
        $accepted = $actual >= $minimum;

        return [
            'actual_word_count' => $actual,
            'minimum_acceptable_words' => $minimum,
            'target_article_length' => $target,
            'length_validation_result' => $accepted ? 'accepted' : 'truncated',
        ];
    }

    /**
     * @return array{
     *     actual_word_count: int,
     *     minimum_acceptable_words: int,
     *     target_article_length: int,
     *     length_validation_result: 'accepted'
     * }
     */
    public function assertAcceptable(string $text, int $targetArticleLength): array
    {
        $result = $this->evaluate($text, $targetArticleLength);
        if ($result['length_validation_result'] !== 'accepted') {
            throw new OutputTruncated(sprintf(
                'Output shorter than minimum acceptable length (actual: %d words, minimum: %d words, target: %d words).',
                $result['actual_word_count'],
                $result['minimum_acceptable_words'],
                $result['target_article_length'],
            ));
        }

        return $result;
    }

    /**
     * Minimum word count để ACCEPT (inclusive).
     * target × ratio là soft floor; actual phải > floor ⇒ minimum = floor + 1.
     */
    public function minimumForTarget(int $targetArticleLength): int
    {
        $target = max(0, $targetArticleLength);
        if ($target <= 0) {
            return max(1, $this->configuredAbsoluteFloorWhenNoTarget());
        }

        $ratio = $this->configuredRatio();
        $softFloor = (int) floor($target * $ratio);

        return max(1, $softFloor + 1);
    }

    public function configuredRatio(): float
    {
        $default = self::DEFAULT_MINIMUM_ACCEPTABLE_RATIO;
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = config('seo-content-ai.article_writing.minimum_acceptable_ratio', $default);
        } catch (\Throwable) {
            return $default;
        }

        if (! is_numeric($value)) {
            return $default;
        }

        $ratio = (float) $value;

        return ($ratio > 0.0 && $ratio <= 1.0) ? $ratio : $default;
    }

    /**
     * @deprecated Dùng configuredRatio() — giữ method để tương thích test/source cũ nếu còn gọi.
     */
    public function configuredMinimum(): int
    {
        // Legacy callers expect "floor for default article target 2000".
        return $this->minimumForTarget(2000);
    }

    public function configuredAbsoluteFloorWhenNoTarget(): int
    {
        $default = self::DEFAULT_ABSOLUTE_FLOOR_WHEN_NO_TARGET;
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = config(
                'seo-content-ai.article_writing.absolute_floor_when_no_target',
                $default,
            );
        } catch (\Throwable) {
            return $default;
        }

        $int = is_numeric($value) ? (int) $value : $default;

        return $int > 0 ? $int : $default;
    }

    public static function isProviderLengthTruncation(?string $finishReason, bool $truncatedFlag = false): bool
    {
        if ($truncatedFlag) {
            return true;
        }

        $reason = strtolower(trim((string) $finishReason));
        if ($reason === '') {
            return false;
        }

        return in_array($reason, [
            'length',
            'max_tokens',
            'max_token',
            'max_output_tokens',
        ], true);
    }
}
