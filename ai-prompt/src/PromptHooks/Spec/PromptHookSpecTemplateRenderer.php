<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Spec;

/**
 * Phase 5A — simple mustache {{var}} render; missing var = error (no silent empty).
 */
final class PromptHookSpecTemplateRenderer
{
    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function render(string $template, array $variables, bool $allowMissing = false): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $matches) use ($variables, $allowMissing): string {
                $key = $matches[1];
                if (! array_key_exists($key, $variables)) {
                    if ($allowMissing) {
                        return '';
                    }

                    throw new \InvalidArgumentException("Missing template variable [{$key}]");
                }

                $value = $variables[$key];
                if ($value === null) {
                    return '';
                }

                return is_scalar($value) ? (string) $value : '';
            },
            $template,
        );
    }
}
