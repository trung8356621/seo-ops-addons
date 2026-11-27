<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks;

use Omnichannel\Addons\AiPrompt\PromptHooks\Data\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use Omnichannel\Addons\AiPrompt\Support\MetaDescriptionOutputGuard;
use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;

final class PromptHookOutputNormalizer
{
    public function __construct(
        private readonly ArticleGenerationLengthValidator $articleLengthValidator = new ArticleGenerationLengthValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Resolved hook input (vd. article_length)
     * @return array{
     *     format: string,
     *     raw: string,
     *     value: string,
     *     length_validation?: array{
     *         actual_word_count: int,
     *         actual_words: int,
     *         minimum_acceptable_words: int,
     *         hard_floor_words: int,
     *         target_article_length: int,
     *         target_words: int,
     *         length_validation_result: string,
     *         outcome: string,
     *         warning_code: ?string,
     *         warning_message: ?string
     *     }
     * }
     */
    public function normalize(PromptHookDefinition $definition, string $rawOutput, array $input = []): array
    {
        $raw = $rawOutput;
        $value = $rawOutput;

        $isMeta = str_contains(strtolower($definition->key), 'meta_description');

        foreach ($definition->outputNormalizeSteps() as $step) {
            // Meta: skip first_non_empty_line — it pins onto "Requirement:" / instruction bullets.
            if ($isMeta && $step === 'first_non_empty_line') {
                continue;
            }
            $value = match ($step) {
                'trim' => trim($value),
                'strip_markdown_fence' => $this->stripMarkdownFence($value),
                'strip_wrapping_quotes' => $this->stripWrappingQuotes($value),
                'first_non_empty_line' => $this->firstNonEmptyLine($value),
                default => $value,
            };
        }

        if ($isMeta) {
            $value = MetaDescriptionOutputGuard::extractCandidate($raw !== '' ? $raw : $value);
            $title = trim((string) ($input['title'] ?? ''));
            if ($value === ''
                || MetaDescriptionOutputGuard::looksLikePromptInstruction($value)
                || MetaDescriptionOutputGuard::looksLikeTitleRestatement($value, $title)
            ) {
                throw new PromptHookException(
                    PromptHookErrorCode::HookOutputInvalid,
                    "Hook [{$definition->key}] returned prompt instructions instead of a meta description.",
                );
            }
            $value = trim($value);
        }

        $validation = $definition->outputValidation();
        if (($validation['not_empty'] ?? false) === true && trim($value) === '') {
            throw new PromptHookException(
                PromptHookErrorCode::HookOutputInvalid,
                "Hook [{$definition->key}] returned empty output.",
            );
        }

        if (isset($validation['min_length']) && is_numeric($validation['min_length'])) {
            $min = (int) $validation['min_length'];
            if ($min > 0 && mb_strlen(trim($value)) < $min) {
                throw new PromptHookException(
                    PromptHookErrorCode::HookOutputInvalid,
                    "Hook [{$definition->key}] output shorter than min_length ({$min}).",
                );
            }
        }
        if (isset($validation['max_length']) && is_numeric($validation['max_length'])) {
            $max = (int) $validation['max_length'];
            if ($max > 0 && mb_strlen(trim($value)) > $max) {
                $value = trim(mb_substr(trim($value), 0, $max));
            }
        }

        $lengthValidation = $this->assertArticleLengthIfNeeded($definition, $validation, $value, $input);

        $result = [
            'format' => $definition->outputFormat(),
            'raw' => $raw,
            'value' => $value,
        ];
        if ($lengthValidation !== null) {
            $result['length_validation'] = $lengthValidation;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $validation
     * @param  array<string, mixed>  $input
     * @return array{
     *     actual_word_count: int,
     *     actual_words: int,
     *     minimum_acceptable_words: int,
     *     hard_floor_words: int,
     *     target_article_length: int,
     *     target_words: int,
     *     length_validation_result: string,
     *     outcome: string,
     *     warning_code: ?string,
     *     warning_message: ?string
     * }|null
     */
    private function assertArticleLengthIfNeeded(
        PromptHookDefinition $definition,
        array $validation,
        string $value,
        array $input,
    ): ?array {
        // Improve / hooks không full-generation → bỏ qua.
        if ($definition->key === 'article.content.improve'
            || $definition->key === 'article.featured_snippet.optimize') {
            return null;
        }

        $unit = strtolower(trim((string) ($validation['length_unit'] ?? '')));
        $isArticleBodyHook = $definition->key === 'article.content.generate';
        if ($unit !== 'words' && ! $isArticleBodyHook) {
            return null;
        }

        if (! array_key_exists('article_length', $input)
            || $input['article_length'] === null
            || $input['article_length'] === '') {
            return null;
        }

        $raw = $input['article_length'];
        $target = is_numeric($raw) ? (int) $raw : 0;
        if (is_string($raw) && $target <= 0 && preg_match('/(\d+)/', $raw, $matches) === 1) {
            $target = (int) $matches[1];
        }
        if ($target <= 0) {
            return null;
        }

        try {
            return $this->articleLengthValidator->assertAcceptable($value, $target);
        } catch (\Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated $exception) {
            throw new PromptHookException(
                PromptHookErrorCode::HookOutputInvalid,
                "Hook [{$definition->key}] ".$exception->getMessage(),
                $exception,
            );
        }
    }

    private function stripMarkdownFence(string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^```(?:\w+)?\s*\n?(.*?)\n?```$/s', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $value;
    }

    private function stripWrappingQuotes(string $value): string
    {
        $trimmed = trim($value);
        if (strlen($trimmed) >= 2) {
            $first = $trimmed[0];
            $last = $trimmed[strlen($trimmed) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return trim(substr($trimmed, 1, -1));
            }
        }

        return $value;
    }

    private function firstNonEmptyLine(string $value): string
    {
        $lines = preg_split('/\R/u', $value) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return trim($value);
    }
}
