<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;


use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;

/**
 * Length contract cho full article generation (generate / legacy rewrite).
 *
 * target_article_length = {{article_length}} trong Prompt (mục tiêu).
 * minimum_acceptable_words = hard guard (config, default 1400) — không vượt target.
 *
 * Improve không dùng class này.
 */
final class ArticleGenerationLengthValidator
{
    public const DEFAULT_MINIMUM_ACCEPTABLE_WORDS = 1400;

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
     * Hard floor từ config; không bao giờ đòi nhiều hơn target Prompt.
     */
    public function minimumForTarget(int $targetArticleLength): int
    {
        $floor = $this->configuredMinimum();
        $target = max(0, $targetArticleLength);
        if ($target <= 0) {
            return $floor;
        }

        return min($target, $floor);
    }

    public function configuredMinimum(): int
    {
        $default = self::DEFAULT_MINIMUM_ACCEPTABLE_WORDS;
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = config('seo-content-ai.article_writing.minimum_acceptable_words', $default);
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
