<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Spec;

/**
 * Phase 5A — settings merge: defaults ← hook JSON ← site override ← caller override.
 * Caller/site không được inject secret keys.
 */
final class PromptHookSettingsMerger
{
    /** @var list<string> */
    private const FORBIDDEN = ['api_key', 'secret', 'token', 'password', 'authorization'];

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $hookSettings
     * @param  array<string, mixed>  $siteOverride
     * @param  array<string, mixed>  $callerOverride
     * @return array<string, mixed>
     */
    public function merge(
        array $defaults,
        array $hookSettings = [],
        array $siteOverride = [],
        array $callerOverride = [],
    ): array {
        $merged = array_merge($defaults, $hookSettings, $siteOverride, $callerOverride);

        foreach (array_keys($merged) as $key) {
            $lower = strtolower((string) $key);
            foreach (self::FORBIDDEN as $fragment) {
                if (str_contains($lower, $fragment)) {
                    unset($merged[$key]);
                    break;
                }
            }
        }

        return $merged;
    }
}
