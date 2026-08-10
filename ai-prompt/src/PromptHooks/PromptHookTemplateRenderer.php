<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks;

use Omnichannel\Addons\AiPrompt\PromptHooks\Data\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;

final class PromptHookTemplateRenderer
{
    /**
     * @param  array<string, mixed>  $resolvedInput
     * @param  array<string, mixed>  $resolvedSettings
     */
    public function render(
        PromptHookDefinition $definition,
        array $resolvedInput,
        array $resolvedSettings,
    ): ?string {
        $template = $definition->template;
        if ($template === null) {
            return null;
        }

        $templateKey = trim((string) ($template['template_key'] ?? ''));
        if ($templateKey === '') {
            if (($template['nullable'] ?? true) === false) {
                throw new PromptHookException(
                    PromptHookErrorCode::HookManifestInvalid,
                    "Hook [{$definition->key}] template key is missing.",
                );
            }

            return null;
        }

        $body = (string) __('seo-content-ai::'.$templateKey);
        if ($body === '' || $body === 'seo-content-ai::'.$templateKey) {
            if (($template['nullable'] ?? true) === false) {
                throw new PromptHookException(
                    PromptHookErrorCode::HookManifestInvalid,
                    "Hook [{$definition->key}] template locale is empty.",
                );
            }

            return null;
        }

        $vars = [];
        foreach ($resolvedInput as $key => $value) {
            $vars[$key] = $this->stringify($value);
        }
        foreach ($resolvedSettings as $key => $value) {
            $vars[$key] = $this->stringify($value);
        }

        return $this->substitute($body, $vars);
    }

    public function position(PromptHookDefinition $definition): string
    {
        $template = $definition->template;
        if (! is_array($template)) {
            return 'after_prompt';
        }

        $position = (string) ($template['position'] ?? 'after_prompt');

        return in_array($position, ['before_prompt', 'after_prompt'], true)
            ? $position
            : 'after_prompt';
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function substitute(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                $key = $matches[1];

                return array_key_exists($key, $variables)
                    ? $variables[$key]
                    : $matches[0];
            },
            $text,
        );
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
