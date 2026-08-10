<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Support;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;

/**
 * Combined input constraint: at least one field in each group must be filled.
 * Declared via hook metadata.require_any_of = [["post_title","keyword"], ...].
 */
final class PromptHookRequireAnyOf
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $metadata
     */
    public static function assertSatisfied(array $input, array $metadata): void
    {
        $groups = $metadata['require_any_of'] ?? null;
        if (! is_array($groups) || $groups === []) {
            return;
        }

        foreach ($groups as $group) {
            if (! is_array($group) || $group === []) {
                continue;
            }

            $fields = [];
            $anyFilled = false;
            foreach ($group as $field) {
                $key = trim((string) $field);
                if ($key === '') {
                    continue;
                }
                $fields[] = $key;
                $value = $input[$key] ?? null;
                if ($value !== null && trim((string) $value) !== '') {
                    $anyFilled = true;
                    break;
                }
            }

            if ($fields === []) {
                continue;
            }

            if (! $anyFilled) {
                throw new InvalidInput(
                    'Missing required hook input ['.implode('|', $fields).'].',
                );
            }
        }
    }
}
