<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\ProviderFailed;
use Omnichannel\Addons\AiPrompt\PromptHooks\Provider\PromptStructuredStrategy;

/**
 * Shared compile of RenderedPromptRequest → provider-bound text.
 * Used by PromptRunnerProviderAdapter (runtime) and composition preview (UI).
 */
final class PromptHookRenderedPromptCompiler
{
    public function compile(
        RenderedPromptRequest $request,
        PromptStructuredStrategy $strategy = PromptStructuredStrategy::PlainText,
    ): string {
        $legacy = trim((string) ($request->metadata['legacy_compiled_prompt'] ?? ''));
        if ($legacy !== '') {
            $compiled = $legacy;
            if ($this->shouldEnforceJson($strategy)) {
                $compiled .= "\n\nReturn valid JSON only. Do not wrap in markdown fences.";
            }

            return $compiled;
        }

        $parts = [];
        if (trim($request->system) !== '') {
            $parts[] = trim($request->system);
        }
        foreach ($request->messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $parts[] = strtoupper($role).":\n".$content;
        }

        $compiled = trim(implode("\n\n", $parts));
        if ($this->shouldEnforceJson($strategy)) {
            $compiled .= "\n\nReturn valid JSON only. Do not wrap in markdown fences.";
        }

        if ($compiled === '') {
            throw new ProviderFailed('Compiled prompt is empty.');
        }

        return $compiled;
    }

    private function shouldEnforceJson(PromptStructuredStrategy $strategy): bool
    {
        return $strategy === PromptStructuredStrategy::PromptEnforcedJson
            || $strategy === PromptStructuredStrategy::JsonMode;
    }
}
