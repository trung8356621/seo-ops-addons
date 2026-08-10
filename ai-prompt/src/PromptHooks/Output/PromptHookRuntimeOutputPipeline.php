<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Output;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidOutput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ProviderRefused;
use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;
use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;

final class PromptHookRuntimeOutputPipeline
{
    public function __construct(
        private readonly MarkdownSectionsOutputParser $markdownSectionsParser = new MarkdownSectionsOutputParser,
        private readonly ArticleGenerationLengthValidator $articleLengthValidator = new ArticleGenerationLengthValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $providerResponse
     * @param  array<string, mixed>  $input  Validated hook input (vd. article_length)
     * @return array{
     *     type: string,
     *     raw: string,
     *     value: mixed,
     *     warnings: list<string>,
     *     sections?: array<string, string>,
     *     ports?: array<string, string>,
     *     length_validation?: array{
     *         actual_word_count: int,
     *         minimum_acceptable_words: int,
     *         target_article_length: int,
     *         length_validation_result: string
     *     }
     * }
     */
    public function process(
        PromptHookDefinition $definition,
        array $providerResponse,
        ?string $correlationId = null,
        array $input = [],
    ): array {
        if (($providerResponse['refused'] ?? false) === true) {
            throw new ProviderRefused('Provider refused to generate content.');
        }

        $finishReason = isset($providerResponse['finish_reason'])
            ? (string) $providerResponse['finish_reason']
            : null;
        if (ArticleGenerationLengthValidator::isProviderLengthTruncation(
            $finishReason,
            (bool) ($providerResponse['truncated'] ?? false),
        )) {
            throw new OutputTruncated('Provider output was truncated.');
        }

        $raw = (string) ($providerResponse['text'] ?? '');
        $type = $definition->outputSchema->type;

        if ($definition->outputSchema->isMarkdownSections()) {
            $parsed = $this->markdownSectionsParser->parse($definition, $raw, $correlationId);

            return [
                'type' => $type,
                'raw' => $parsed->raw,
                'value' => $parsed->toArray(),
                'warnings' => [],
                'sections' => $parsed->sections,
                'ports' => $parsed->ports,
            ];
        }

        $value = $raw;
        $warnings = [];

        foreach ($definition->outputSchema->normalize as $step) {
            $value = match ($step) {
                'trim' => trim((string) $value),
                'strip_markdown_fence' => $this->stripMarkdownFence((string) $value),
                'strip_wrapping_quotes' => $this->stripWrappingQuotes((string) $value),
                'first_non_empty_line' => $this->firstNonEmptyLine((string) $value),
                default => $value,
            };
        }

        $validation = $definition->outputSchema->validation;
        if (($validation['not_empty'] ?? false) === true && trim((string) $value) === '') {
            throw new InvalidOutput('Output is empty.');
        }

        $rejectMarkers = ($validation['reject_previous_step_markers'] ?? false) === true
            || ($validation['reject_task_markers'] ?? false) === true;
        if ($rejectMarkers
            && is_string($value)
            && (str_contains($value, '[START') || str_contains($value, '[END'))) {
            throw new InvalidOutput('Output contains previous-step markers.');
        }

        $parsed = $value;
        if (in_array($type, ['json', 'structured_object'], true)) {
            $json = is_string($value) ? $value : (string) json_encode($value);
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new InvalidOutput('Output is not valid JSON: '.$exception->getMessage());
            }
            $parsed = $decoded;
        }

        if (($validation['json_object'] ?? false) === true && ! is_array($parsed)) {
            throw new InvalidOutput('Output JSON must be an object/array.');
        }

        if (($validation['reject_provider_preamble'] ?? false) === true && is_string($parsed)) {
            $this->assertNoProviderPreamble($parsed);
        }

        $lengthValidation = null;
        if (is_string($parsed)) {
            $lengthValidation = $this->assertLengthConstraints($parsed, $validation, $input, $warnings);
        }

        $result = [
            'type' => $type,
            'raw' => $raw,
            'value' => $parsed,
            'warnings' => $warnings,
        ];
        if ($lengthValidation !== null) {
            $result['length_validation'] = $lengthValidation;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $validation
     * @param  array<string, mixed>  $input
     * @param  list<string>  $warnings
     * @return array{
     *     actual_word_count: int,
     *     minimum_acceptable_words: int,
     *     target_article_length: int,
     *     length_validation_result: string
     * }|null
     */
    private function assertLengthConstraints(
        string $parsed,
        array $validation,
        array $input,
        array &$warnings,
    ): ?array {
        $unit = strtolower(trim((string) ($validation['length_unit'] ?? 'chars')));
        if ($unit !== 'words') {
            $unit = 'chars';
        }

        $schemaMin = $validation['min_length'] ?? $validation['minimum_length'] ?? null;
        $min = $schemaMin !== null ? (int) $schemaMin : null;
        $lengthMeta = null;

        // Words + article_length: target = Prompt; hard min = config floor (≤ target).
        if ($unit === 'words') {
            $articleLength = $this->resolveArticleLengthWords($input);
            if ($articleLength !== null && $articleLength > 0) {
                $lengthMeta = $this->articleLengthValidator->assertAcceptable($parsed, $articleLength);
                $min = $lengthMeta['minimum_acceptable_words'];
            }
        }

        $measured = PromptTextMetrics::measure($parsed, $unit);

        if ($lengthMeta === null && $min !== null && $measured < $min) {
            throw new OutputTruncated(
                $unit === 'words'
                    ? "Output shorter than minimum_length ({$measured} words < {$min} words)."
                    : "Output shorter than minimum_length ({$measured} chars < {$min}).",
            );
        }

        if (isset($validation['max_length'])) {
            $max = (int) $validation['max_length'];
            if ($measured > $max) {
                throw new InvalidOutput(
                    $unit === 'words'
                        ? "Output longer than max_length ({$measured} words > {$max} words)."
                        : "Output longer than max_length ({$measured} chars > {$max}).",
                );
            }
        }

        return $lengthMeta;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveArticleLengthWords(array $input): ?int
    {
        if (! array_key_exists('article_length', $input) || $input['article_length'] === null || $input['article_length'] === '') {
            return null;
        }

        $raw = $input['article_length'];
        if (is_int($raw)) {
            return max(0, $raw);
        }
        if (is_numeric($raw)) {
            return max(0, (int) $raw);
        }

        if (is_string($raw) && preg_match('/(\d+)/', $raw, $matches) === 1) {
            return max(0, (int) $matches[1]);
        }

        return null;
    }

    private function assertNoProviderPreamble(string $value): void
    {
        $trimmed = ltrim($value);
        if (preg_match(
            '/^(sure[,!]?\s+|here(?:\'s| is)\s+(?:the|an?|your)\s+|certainly[,!]?\s+|of course[,!]?\s+|absolutely[,!]?\s+|i(?:\'ve| have)\s+(?:written|rewritten|created)\b)/iu',
            $trimmed,
        ) === 1) {
            throw new InvalidOutput('Output looks like provider preamble.');
        }
    }

    private function stripMarkdownFence(string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^```(?:\w+)?\s*\n?(.*?)\n?```$/s', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $trimmed;
    }

    private function stripWrappingQuotes(string $value): string
    {
        $trimmed = trim($value);
        if (
            (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
        ) {
            return trim(mb_substr($trimmed, 1, -1));
        }

        return $trimmed;
    }

    private function firstNonEmptyLine(string $value): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }
}
