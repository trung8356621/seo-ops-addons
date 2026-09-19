<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;

/**
 * Length contract cho full article generation (generate / legacy rewrite).
 *
 * target_words = {{article_length}} — desired size, never the hard-fail threshold.
 * hard_floor_words = max(absolute minimum, target × ratio).
 *
 * Below target but at/above hard floor → success_with_warning (no router fallback).
 * Below hard floor, or provider max-token truncation → hard failure.
 *
 * Improve không dùng class này.
 */
final class ArticleGenerationLengthValidator
{
    public const DEFAULT_MINIMUM_ACCEPTABLE_RATIO = 0.5;

    /**
     * Absolute usability floor (also used when target ≤ 0).
     */
    public const DEFAULT_ABSOLUTE_MINIMUM_WORDS = 300;

    /** @deprecated Use DEFAULT_ABSOLUTE_MINIMUM_WORDS */
    public const DEFAULT_ABSOLUTE_FLOOR_WHEN_NO_TARGET = self::DEFAULT_ABSOLUTE_MINIMUM_WORDS;

    public const RESULT_ACCEPTED = 'accepted';

    public const RESULT_ACCEPTED_WITH_WARNING = 'accepted_with_warning';

    public const RESULT_TRUNCATED = 'truncated';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_SUCCESS_WITH_WARNING = 'success_with_warning';

    public const OUTCOME_FAILED = 'failed';

    public const WARNING_BELOW_TARGET = 'ARTICLE_BELOW_TARGET_LENGTH';

    public const HARD_FAILURE_BELOW_FLOOR = 'ARTICLE_BELOW_HARD_FLOOR';

    public const UI_WARNING = 'Article is shorter than the requested target.';

    /**
     * @return array{
     *     actual_word_count: int,
     *     actual_words: int,
     *     minimum_acceptable_words: int,
     *     hard_floor_words: int,
     *     target_article_length: int,
     *     target_words: int,
     *     length_validation_result: 'accepted'|'accepted_with_warning'|'truncated',
     *     outcome: 'success'|'success_with_warning'|'failed',
     *     warning_code: ?string,
     *     warning_message: ?string
     * }
     */
    public function evaluate(string $text, int $targetArticleLength): array
    {
        $target = max(0, $targetArticleLength);
        $hardFloor = $this->hardFloorForTarget($target);
        $this->assertNotSectionedFreeLegacyPath(__METHOD__, $target, $hardFloor);

        $actual = PromptTextMetrics::wordCount($text);

        $base = [
            'actual_word_count' => $actual,
            'actual_words' => $actual,
            'minimum_acceptable_words' => $hardFloor,
            'hard_floor_words' => $hardFloor,
            'target_article_length' => $target,
            'target_words' => $target,
        ];

        if ($target > 0 && $actual >= $target) {
            return $base + [
                'length_validation_result' => self::RESULT_ACCEPTED,
                'outcome' => self::OUTCOME_SUCCESS,
                'warning_code' => null,
                'warning_message' => null,
            ];
        }

        if ($actual < $hardFloor) {
            return $base + [
                'length_validation_result' => self::RESULT_TRUNCATED,
                'outcome' => self::OUTCOME_FAILED,
                'warning_code' => null,
                'warning_message' => null,
            ];
        }

        if ($target > 0 && $actual < $target) {
            return $base + [
                'length_validation_result' => self::RESULT_ACCEPTED_WITH_WARNING,
                'outcome' => self::OUTCOME_SUCCESS_WITH_WARNING,
                'warning_code' => self::WARNING_BELOW_TARGET,
                'warning_message' => self::warningMessage($actual, $target),
            ];
        }

        return $base + [
            'length_validation_result' => self::RESULT_ACCEPTED,
            'outcome' => self::OUTCOME_SUCCESS,
            'warning_code' => null,
            'warning_message' => null,
        ];
    }

    /**
     * Throws only for below-hard-floor (unusable). Below-target warning is returned, not thrown.
     *
     * @return array{
     *     actual_word_count: int,
     *     actual_words: int,
     *     minimum_acceptable_words: int,
     *     hard_floor_words: int,
     *     target_article_length: int,
     *     target_words: int,
     *     length_validation_result: 'accepted'|'accepted_with_warning',
     *     outcome: 'success'|'success_with_warning',
     *     warning_code: ?string,
     *     warning_message: ?string
     * }
     */
    public function assertAcceptable(string $text, int $targetArticleLength): array
    {
        $result = $this->evaluate($text, $targetArticleLength);
        if ($result['outcome'] === self::OUTCOME_FAILED) {
            throw new OutputTruncated(sprintf(
                '%s: content shorter than usability floor (actual: %d words, hard_floor: %d words, target: %d words).',
                self::HARD_FAILURE_BELOW_FLOOR,
                $result['actual_words'],
                $result['hard_floor_words'],
                $result['target_words'],
            ));
        }

        return $result;
    }

    public static function isWarningResult(array $lengthValidation): bool
    {
        $outcome = (string) ($lengthValidation['outcome'] ?? '');
        $result = (string) ($lengthValidation['length_validation_result'] ?? '');
        $code = (string) ($lengthValidation['warning_code'] ?? '');

        return $outcome === self::OUTCOME_SUCCESS_WITH_WARNING
            || $result === self::RESULT_ACCEPTED_WITH_WARNING
            || $code === self::WARNING_BELOW_TARGET;
    }

    public static function isHardFailureResult(array $lengthValidation): bool
    {
        return (string) ($lengthValidation['outcome'] ?? '') === self::OUTCOME_FAILED
            || (string) ($lengthValidation['length_validation_result'] ?? '') === self::RESULT_TRUNCATED;
    }

    public static function warningMessage(int $actualWords, int $targetWords): string
    {
        return sprintf('Article is shorter than target: %d/%d words.', $actualWords, $targetWords);
    }

    /**
     * Hard invariant: sectioned_free must never hit whole-article length gates.
     * Surfaces SECTIONED_FREE_LEGACY_VALIDATOR_REACHED instead of OUTPUT_TRUNCATED 501/1000.
     */
    private function assertNotSectionedFreeLegacyPath(string $classMethod, int $target, int $minimum): void
    {
        if (! class_exists(\Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeExecutionGuard::class)) {
            return;
        }

        \Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeExecutionGuard::assertLegacyValidatorNotReached(
            $classMethod,
            $target,
            $minimum,
        );
    }

    /**
     * Usability floor (inclusive). Meeting this is enough to accept; target remains a warning line.
     */
    public function hardFloorForTarget(int $targetArticleLength): int
    {
        $absolute = max(1, $this->configuredAbsoluteMinimum());
        $target = max(0, $targetArticleLength);
        if ($target <= 0) {
            return $absolute;
        }

        $ratioFloor = (int) floor($target * $this->configuredRatio());

        return max($absolute, max(1, $ratioFloor));
    }

    /**
     * @deprecated Use hardFloorForTarget() — kept for callers/tests.
     */
    public function minimumForTarget(int $targetArticleLength): int
    {
        return $this->hardFloorForTarget($targetArticleLength);
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
        return $this->hardFloorForTarget(2000);
    }

    public function configuredAbsoluteMinimum(): int
    {
        $default = self::DEFAULT_ABSOLUTE_MINIMUM_WORDS;
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = config('seo-content-ai.article_writing.absolute_minimum_words');
            if (! is_numeric($value)) {
                $value = config(
                    'seo-content-ai.article_writing.absolute_floor_when_no_target',
                    $default,
                );
            }
        } catch (\Throwable) {
            return $default;
        }

        $int = is_numeric($value) ? (int) $value : $default;

        return $int > 0 ? $int : $default;
    }

    /**
     * @deprecated Use configuredAbsoluteMinimum()
     */
    public function configuredAbsoluteFloorWhenNoTarget(): int
    {
        return $this->configuredAbsoluteMinimum();
    }

    public static function isProviderLengthTruncation(?string $finishReason, bool $truncatedFlag = false): bool
    {
        if ($truncatedFlag) {
            return true;
        }

        return (new \Omnichannel\Addons\AiPrompt\Support\AiProviderTerminalReasonNormalizer)
            ->isLengthStopReason($finishReason);
    }
}
