<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

/**
 * Per-hook promotion sample thresholds — config only, no runtime hardcode.
 */
final class PromptHookPromotionThresholds
{
    public function forHook(string $hookKey): int
    {
        $map = config('seo-content-ai.prompt_hooks.promotion_thresholds.hooks', []);
        if (is_array($map) && isset($map[$hookKey])) {
            return max(1, (int) $map[$hookKey]);
        }

        $default = config('seo-content-ai.prompt_hooks.promotion_thresholds.default');
        if ($default !== null) {
            return max(1, (int) $default);
        }

        return max(1, (int) config('seo-content-ai.prompt_hooks.promotion_min_samples', 20));
    }
}
