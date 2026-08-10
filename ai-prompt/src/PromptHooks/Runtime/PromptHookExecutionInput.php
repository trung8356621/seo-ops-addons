<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Canonical execution envelope.
 *
 * @phpstan-type ContextMap array<string, scalar|null>
 */
final class PromptHookExecutionInput
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $previousOutputs
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly array $context,
        public readonly array $input,
        public readonly array $previousOutputs,
        public readonly array $settings,
    ) {
        $this->assertNoForbiddenValues($context, 'context');
        $this->assertNoForbiddenValues($input, 'input');
        $this->assertNoForbiddenValues($previousOutputs, 'previous_outputs');
        $this->assertNoForbiddenValues($settings, 'settings');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
            input: is_array($payload['input'] ?? null) ? $payload['input'] : [],
            previousOutputs: is_array($payload['previous_outputs'] ?? null) ? $payload['previous_outputs'] : [],
            settings: is_array($payload['settings'] ?? null) ? $payload['settings'] : [],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNoForbiddenValues(array $data, string $section): void
    {
        foreach ($data as $key => $value) {
            if ($value instanceof Model) {
                throw new InvalidInput("Eloquent Model forbidden in {$section}.{$key}");
            }
            if ($value instanceof \Closure || is_resource($value) || is_object($value) && ! $value instanceof \Stringable) {
                if ($value instanceof Collection) {
                    foreach ($value as $item) {
                        if ($item instanceof Model) {
                            throw new InvalidInput("Eloquent Model in Collection forbidden in {$section}.{$key}");
                        }
                    }
                } elseif (! is_array($value)) {
                    // Allow arrays only deeper
                    if (is_object($value) && ! ($value instanceof \UnitEnum)) {
                        throw new InvalidInput("Object forbidden in {$section}.{$key}");
                    }
                }
            }
            if (is_array($value)) {
                $this->assertNoForbiddenValues($value, $section.'.'.$key);
            }
        }
    }
}
