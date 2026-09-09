<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Output;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidOutput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\OutputTruncated;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ProviderRefused;
use Omnichannel\Addons\AiPrompt\Support\OutputValidationContractRegistry;
use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;
use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;

final class PromptHookRuntimeOutputPipeline
{
    public function __construct(
        private readonly MarkdownSectionsOutputParser $markdownSectionsParser = new MarkdownSectionsOutputParser,
        private readonly ArticleGenerationLengthValidator $articleLengthValidator = new ArticleGenerationLengthValidator,
        private readonly OutputValidationContractRegistry $validationContracts = new OutputValidationContractRegistry,
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
     *     },
     *     validation_contract?: string,
     *     validators_applied?: list<string>
     * }
     */
    public function process(
        PromptHookDefinition $definition,
        array $providerResponse,
        ?string $correlationId = null,
        array $input = [],
    ): array {
        $contract = $this->validationContracts->resolve(
            $definition->outputContractKey() ?? $definition->key->value,
            $definition->key->value,
        );
        $validatorsApplied = [];

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
            $validatorsApplied[] = 'outline_structure';

            return [
                'type' => $type,
                'raw' => $parsed->raw,
                'value' => $parsed->toArray(),
                'warnings' => [],
                'sections' => $parsed->sections,
                'ports' => $parsed->ports,
                'validation_contract' => $contract['contract'],
                'validators_applied' => $validatorsApplied,
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
            $validatorsApplied[] = 'non_empty';
            throw new InvalidOutput('Output is empty.');
        }
        if (($validation['not_empty'] ?? false) === true) {
            $validatorsApplied[] = 'non_empty';
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
            $validatorsApplied[] = 'json_schema';
        }

        if (($validation['json_object'] ?? false) === true && ! is_array($parsed)) {
            throw new InvalidOutput('Output JSON must be an object/array.');
        }

        if (($validation['reject_provider_preamble'] ?? false) === true && is_string($parsed)) {
            $this->assertNoProviderPreamble($parsed);
        }

        $lengthValidation = null;
        if (is_string($parsed)) {
            $lengthValidation = $this->assertLengthConstraints(
                $parsed,
                $validation,
                $input,
                $warnings,
                $contract,
                $validatorsApplied,
            );
        }

        $result = [
            'type' => $type,
            'raw' => $raw,
            'value' => $parsed,
            'warnings' => $warnings,
            'validation_contract' => $contract['contract'],
            'validators_applied' => array_values(array_unique($validatorsApplied)),
        ];
        if ($lengthValidation !== null) {
            $result['length_validation'] = $lengthValidation;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $validation
     * @param  array<string, mixed>  $input
     * @param  array{
     *     contract: string,
     *     validators: list<string>,
     *     allows_article_min_words: bool,
     *     length_unit: ?string
     * }  $contract
     * @param  list<string>  $warnings
     * @param  list<string>  $validatorsApplied
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
        array $contract,
        array &$validatorsApplied,
    ): ?array {
        $unit = strtolower(trim((string) ($validation['length_unit'] ?? $contract['length_unit'] ?? 'chars')));
        if ($unit !== 'words') {
            $unit = 'chars';
        }

        $schemaMin = $validation['min_length'] ?? $validation['minimum_length'] ?? null;
        $min = $schemaMin !== null ? (int) $schemaMin : null;
        $lengthMeta = null;

        // Article min-words when contract allows, OR schema explicitly uses words
        // for a non-blocked prompt type. Never inherit into Outline/Vocab/Meta/FAQ.
        $blockArticleMinWords = ! $contract['allows_article_min_words']
            && $this->isKnownNonArticleContentContract((string) $contract['contract']);

        if ($unit === 'words' && ! $blockArticleMinWords) {
            $articleLength = $this->resolveArticleLengthWords($input);
            if ($articleLength !== null && $articleLength > 0) {
                $this->assertSectionedFreeDidNotReachLegacyValidator($input, $articleLength);
                $lengthMeta = $this->articleLengthValidator->assertAcceptable($parsed, $articleLength);
                $min = $lengthMeta['minimum_acceptable_words'];
                $validatorsApplied[] = 'min_words:'.$min;
                $validatorsApplied[] = 'target_words:'.$articleLength;
            }
        } elseif ($unit === 'words' && $blockArticleMinWords) {
            // Explicitly block article_length inheritance for Outline/Vocab/Meta/FAQ.
            $unit = 'chars';
            if ($schemaMin === null && isset($validation['min_length'])) {
                $min = (int) $validation['min_length'];
            }
        }

        $measured = PromptTextMetrics::measure($parsed, $unit);

        if ($lengthMeta === null && $min !== null && $measured < $min) {
            if ($unit === 'chars') {
                $validatorsApplied[] = 'min_chars:'.$min;
            }
            throw new OutputTruncated(
                $unit === 'words'
                    ? "Output shorter than minimum_length ({$measured} words < {$min} words)."
                    : "Output shorter than minimum_length ({$measured} chars < {$min}).",
            );
        }

        if (isset($validation['max_length'])) {
            $max = (int) $validation['max_length'];
            if ($measured > $max) {
                $validatorsApplied[] = 'max_'.$unit.':'.$max;
                throw new InvalidOutput(
                    $unit === 'words'
                        ? "Output longer than max_length ({$measured} words > {$max} words)."
                        : "Output longer than max_length ({$measured} chars > {$max}).",
                );
            }
        }

        return $lengthMeta;
    }

    private function isKnownNonArticleContentContract(string $contract): bool
    {
        $c = strtolower(trim($contract));

        return str_contains($c, 'outline')
            || str_contains($c, 'vocabulary')
            || str_contains($c, 'meta_description')
            || str_contains($c, 'meta-description')
            || str_contains($c, 'faq')
            || $c === 'meta';
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

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertSectionedFreeDidNotReachLegacyValidator(array $input, int $target): void
    {
        $strategy = \Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy::tryFromMixed(
            $input['resolved_generation_strategy'] ?? $input['generation_strategy'] ?? null,
        );
        if ($strategy === null || ! $strategy->isSectionedFree()) {
            if (class_exists(\Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeExecutionGuard::class)) {
                \Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeExecutionGuard::assertLegacyValidatorNotReached(
                    self::class.'::assertLengthConstraints',
                    $target,
                    (new ArticleGenerationLengthValidator)->minimumForTarget($target),
                );
            }

            return;
        }

        $minimum = (new ArticleGenerationLengthValidator)->minimumForTarget($target);
        throw new \Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException(
            'SECTIONED_FREE_LEGACY_VALIDATOR_REACHED: whole-article length validator invoked while '
            .'resolved_generation_strategy=sectioned_free. '
            .'class/method='.self::class.'::assertLengthConstraints'
            .' target='.$target
            .' minimum='.$minimum,
            0,
            null,
            [
                'failure_code' => 'SECTIONED_FREE_LEGACY_VALIDATOR_REACHED',
                'strategy' => 'sectioned_free',
                'class_method' => self::class.'::assertLengthConstraints',
                'target' => $target,
                'minimum' => $minimum,
                'retryable' => false,
            ],
        );
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

        return $value;
    }

    private function stripWrappingQuotes(string $value): string
    {
        $trimmed = trim($value);
        if (
            (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
        ) {
            return trim(substr($trimmed, 1, -1));
        }

        return $value;
    }

    private function firstNonEmptyLine(string $value): string
    {
        foreach (preg_split("/\r\n|\n|\r/", $value) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                return $line;
            }
        }

        return trim($value);
    }
}
