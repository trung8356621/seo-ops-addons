<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookSettingsMerger;

final class PromptHookRuntimeSettingsResolver
{
    private const ALLOWED_MODEL = ['temperature', 'max_tokens', 'top_p', 'timeout_ms'];

    public function __construct(
        private readonly PromptHookSettingsMerger $merger = new PromptHookSettingsMerger,
    ) {}

    /**
     * @param  array<string, mixed>  $siteOverride
     * @param  array<string, mixed>  $executionOverride
     * @return array{hook: array<string, mixed>, model: array<string, mixed>}
     */
    public function resolve(
        PromptHookDefinition $definition,
        array $siteOverride = [],
        array $executionOverride = [],
    ): array {
        $defaults = [];
        foreach ($definition->settingsSchema as $key => $schema) {
            if (is_array($schema) && array_key_exists('default', $schema)) {
                $defaults[(string) $key] = $schema['default'];
            }
        }

        $hookSettings = $this->merger->merge($defaults, [], $siteOverride, $executionOverride);

        // Whitelist to schema keys only
        $allowed = array_map('strval', array_keys($definition->settingsSchema));
        foreach (array_keys($hookSettings) as $key) {
            if ($allowed !== [] && ! in_array((string) $key, $allowed, true)) {
                throw new InvalidInput("Unknown setting [{$key}]");
            }
        }

        foreach ($definition->settingsSchema as $key => $schema) {
            if (! is_array($schema) || ! array_key_exists($key, $hookSettings)) {
                continue;
            }
            $value = $hookSettings[$key];
            if (($schema['type'] ?? '') === 'integer') {
                $int = (int) $value;
                if (isset($schema['min'])) {
                    $int = max((int) $schema['min'], $int);
                }
                if (isset($schema['max'])) {
                    $int = min((int) $schema['max'], $int);
                }
                $hookSettings[$key] = $int;
            }
        }

        $modelSettings = [];
        foreach ($definition->model->settings as $key => $value) {
            $key = (string) $key;
            if (! in_array($key, self::ALLOWED_MODEL, true)) {
                throw new InvalidInput("Unsupported model setting [{$key}]");
            }
            $modelSettings[$key] = $value;
        }

        return ['hook' => $hookSettings, 'model' => $modelSettings];
    }
}
