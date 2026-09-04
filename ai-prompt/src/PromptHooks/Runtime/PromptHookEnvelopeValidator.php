<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookRequireAnyOf;

final class PromptHookEnvelopeValidator
{
    private const CONTEXT_WHITELIST = [
        'site_id', 'article_id', 'actor_id', 'team_id', 'correlation_id',
        'locale', 'site_locale', 'article_locale', 'caller_locale',
        'language', 'language_name', 'prompt_id', 'legacy_compiled_prompt',
        'connection_id',
        // Content Project run association — needed so PromptResults link into the active run.
        'run_id', 'project_run_id', 'run_item_id', 'attempt',
        'project_task_id', 'task_id', 'project_id',
        'outline_subtask',
    ];

    public function __construct(
        private readonly PromptHookPreviousOutputsGuard $previousOutputsGuard = new PromptHookPreviousOutputsGuard,
    ) {}

    /**
     * @return array{
     *   context: array<string, mixed>,
     *   input: array<string, mixed>,
     *   previous_outputs: array<string, mixed>,
     *   settings: array<string, mixed>
     * }
     */
    public function validate(PromptHookDefinition $definition, PromptHookExecutionInput $envelope): array
    {
        $context = [];
        foreach ($envelope->context as $key => $value) {
            $key = (string) $key;
            if (! in_array($key, self::CONTEXT_WHITELIST, true)) {
                throw new InvalidInput("context.{$key} is not whitelisted");
            }
            $context[$key] = $value;
        }

        $input = [];
        foreach ($definition->inputSchema->fields as $field => $schema) {
            if (in_array($field, ['previous_outputs', 'context', 'settings'], true)) {
                continue;
            }
            $required = (bool) ($schema['required'] ?? false);
            $has = array_key_exists($field, $envelope->input);
            $value = $has ? $envelope->input[$field] : ($schema['default'] ?? null);

            if (! $has && $required && ($value === null || $value === '')) {
                throw new InvalidInput("input.{$field} is required");
            }

            if ($value !== null && isset($schema['max_length']) && is_string($value)) {
                $max = (int) $schema['max_length'];
                if (mb_strlen($value) > $max) {
                    throw new InvalidInput("input.{$field} exceeds max_length={$max}");
                }
            }

            $type = (string) ($schema['type'] ?? 'string');
            if ($value !== null && in_array($type, ['integer', 'int'], true)) {
                if (! is_int($value) && ! is_numeric($value)) {
                    throw new InvalidInput("input.{$field} must be integer");
                }
                $int = (int) $value;
                if (isset($schema['minimum']) && $int < (int) $schema['minimum']) {
                    throw new InvalidInput("input.{$field} below minimum");
                }
                if (isset($schema['maximum']) && $int > (int) $schema['maximum']) {
                    throw new InvalidInput("input.{$field} above maximum");
                }
                $value = $int;
            }
            if ($value !== null && in_array($type, ['boolean', 'bool'], true) && ! is_bool($value)) {
                throw new InvalidInput("input.{$field} must be boolean");
            }

            if ($has || $value !== null) {
                $input[$field] = $value;
            }
        }

        foreach (array_keys($envelope->input) as $key) {
            if (! array_key_exists((string) $key, $definition->inputSchema->fields)) {
                throw new InvalidInput("Unknown input key [{$key}]");
            }
        }

        $previous = $this->previousOutputsGuard->enforce($definition, $envelope->previousOutputs);
        PromptHookRequireAnyOf::assertSatisfied($input, $definition->metadata);

        return [
            'context' => $context,
            'input' => $input,
            'previous_outputs' => $previous,
            'settings' => $envelope->settings,
        ];
    }
}
