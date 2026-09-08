<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Detects whether outbound adapters must omit application-generated output ceilings.
 */
final class ArticleOutboundCeilingPolicy
{
    /**
     * Anthropic Messages requires max_tokens — use native/large ceiling, never task-derived.
     */
    public const PROVIDER_REQUIRED_OUTPUT_CEILING = 8192;

    /**
     * @param  array<string, mixed>  $options
     */
    public static function shouldOmitApplicationCeiling(array $options): bool
    {
        if (($options['omit_application_output_ceiling'] ?? false) === true) {
            return true;
        }

        return ArticleContentGenerationHooks::matches(
            is_string($options['hook_key'] ?? null) ? (string) $options['hook_key'] : null,
        );
    }
}
