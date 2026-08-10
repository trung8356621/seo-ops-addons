<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InputTooLarge;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;
use Illuminate\Support\Facades\Log;

final class PromptHookPreviousOutputsGuard
{
    /**
     * @param  array<string, mixed>  $previousOutputs
     * @return array<string, mixed>
     */
    public function enforce(PromptHookDefinition $definition, array $previousOutputs): array
    {
        $limits = $definition->limits;
        if (count($previousOutputs) > $limits->maxPreviousOutputsItems) {
            throw new InputTooLarge(
                "previous_outputs exceeds max_items={$limits->maxPreviousOutputsItems}",
            );
        }

        if ($limits->allowedPreviousOutputKeys !== null) {
            foreach (array_keys($previousOutputs) as $key) {
                if (! in_array((string) $key, $limits->allowedPreviousOutputKeys, true)) {
                    throw new InvalidInput("previous_outputs key [{$key}] not allowed");
                }
            }
        }

        $total = 0;
        $out = [];
        foreach ($previousOutputs as $key => $value) {
            $fieldSchema = $definition->inputSchema->fields['previous_outputs'] ?? [];
            $itemSchema = is_array($fieldSchema['items'] ?? null)
                ? ($fieldSchema['items'][(string) $key] ?? [])
                : [];
            $allowTruncate = (bool) ($itemSchema['truncate'] ?? ($fieldSchema['truncate'] ?? false));

            $encoded = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
            $bytes = strlen($encoded);
            if ($bytes > $limits->maxPreviousOutputsItemBytes) {
                if (! $allowTruncate) {
                    throw new InputTooLarge(
                        "previous_outputs.{$key} exceeds max_item_bytes={$limits->maxPreviousOutputsItemBytes}",
                    );
                }
                $encoded = substr($encoded, 0, $limits->maxPreviousOutputsItemBytes);
                Log::info('prompt_hook.previous_outputs_truncated', [
                    'hook_key' => $definition->key->value,
                    'field' => (string) $key,
                    'original_bytes' => $bytes,
                    'resulting_bytes' => strlen($encoded),
                ]);
                $value = $encoded;
                $bytes = strlen($encoded);
            }

            if (is_string($value)) {
                $value = $this->stripMarkers($value);
            }

            $total += $bytes;
            $out[(string) $key] = $value;
        }

        if ($total > $limits->maxPreviousOutputsTotalBytes) {
            throw new InputTooLarge(
                "previous_outputs exceeds max_total_bytes={$limits->maxPreviousOutputsTotalBytes}",
            );
        }

        return $out;
    }

    private function stripMarkers(string $value): string
    {
        $value = preg_replace('/\[START[^\]]*\]/u', '', $value) ?? $value;
        $value = preg_replace('/\[END[^\]]*\]/u', '', $value) ?? $value;

        return trim($value);
    }
}
