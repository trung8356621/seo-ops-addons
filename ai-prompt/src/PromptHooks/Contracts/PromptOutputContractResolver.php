<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Contracts;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;

/**
 * Resolve output_contract keys into final prompt text.
 *
 * Supports optional nested {{include:contract.key}} inside contract bodies.
 * Never leaves unresolved include tokens in provider-bound prompts.
 */
final class PromptOutputContractResolver
{
    private const INCLUDE_PATTERN = '/\{\{\s*include:([a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+)\s*\}\}/';

    public function __construct(
        private readonly PromptOutputContractCatalog $catalog,
    ) {}

    /**
     * @return array{
     *     text: string,
     *     contracts: list<array{key: string, version: string}>
     * }
     */
    public function resolve(?string $contractKey): array
    {
        $contractKey = trim((string) $contractKey);
        if ($contractKey === '') {
            return ['text' => '', 'contracts' => []];
        }

        $stack = [];
        $seen = [];
        $contracts = [];
        $text = $this->resolveKey($contractKey, $stack, $seen, $contracts);

        return [
            'text' => trim($text),
            'contracts' => $contracts,
        ];
    }

    /**
     * Append resolved contract once to an existing prompt body.
     *
     * @return array{
     *     prompt: string,
     *     contracts: list<array{key: string, version: string}>
     * }
     */
    public function appendToPrompt(string $prompt, ?string $contractKey): array
    {
        $resolved = $this->resolve($contractKey);
        $contractText = $resolved['text'];
        if ($contractText === '') {
            return [
                'prompt' => trim($prompt),
                'contracts' => [],
            ];
        }

        $prompt = trim($prompt);
        // Deduplicate: same contract body already present.
        if ($prompt !== '' && str_contains($prompt, $contractText)) {
            return [
                'prompt' => $prompt,
                'contracts' => $resolved['contracts'],
            ];
        }

        $combined = $prompt === ''
            ? $contractText
            : $prompt."\n\n".$contractText;

        return [
            'prompt' => $combined,
            'contracts' => $resolved['contracts'],
        ];
    }

    /**
     * @param  list<string>  $stack
     * @param  array<string, true>  $seen
     * @param  list<array{key: string, version: string}>  $contracts
     */
    private function resolveKey(string $key, array &$stack, array &$seen, array &$contracts): string
    {
        if (in_array($key, $stack, true)) {
            throw new PromptHookFailure(
                PromptHookFailureCode::DefinitionInvalid,
                'Circular output contract include: '.implode(' -> ', [...$stack, $key]),
            );
        }

        if (isset($seen[$key])) {
            // Already expanded once in this resolve tree — skip duplicate body.
            return '';
        }

        $contract = $this->catalog->get($key);
        $seen[$key] = true;
        $contracts[] = [
            'key' => $contract->key,
            'version' => $contract->version,
        ];

        $stack[] = $key;
        $body = $this->expandIncludes($contract->body, $stack, $seen, $contracts);
        array_pop($stack);

        return $body;
    }

    /**
     * @param  list<string>  $stack
     * @param  array<string, true>  $seen
     * @param  list<array{key: string, version: string}>  $contracts
     */
    private function expandIncludes(string $body, array &$stack, array &$seen, array &$contracts): string
    {
        return (string) preg_replace_callback(
            self::INCLUDE_PATTERN,
            function (array $matches) use (&$stack, &$seen, &$contracts): string {
                $included = $this->resolveKey($matches[1], $stack, $seen, $contracts);

                return $included;
            },
            $body,
        );
    }
}
