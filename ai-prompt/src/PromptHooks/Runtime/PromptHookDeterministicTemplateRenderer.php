<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\TemplateRenderFailed;

final class PromptHookDeterministicTemplateRenderer
{
    private ?PromptOutputContractResolver $contractResolver;

    public function __construct(
        ?PromptOutputContractResolver $contractResolver = null,
    ) {
        $this->contractResolver = $contractResolver;
    }

    private function contracts(): PromptOutputContractResolver
    {
        return $this->contractResolver ??= new PromptOutputContractResolver(
            new PromptOutputContractCatalog(PromptOutputContractCatalog::defaultDirectory()),
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array{locale_code: string, language_name: string}  $locale
     * @param  array<string, mixed>  $modelSettings
     * @param  array<string, mixed>  $metadata
     */
    public function render(
        PromptHookDefinition $definition,
        array $variables,
        array $locale,
        array $modelSettings,
        array $metadata = [],
    ): RenderedPromptRequest {
        $vars = $variables;
        $vars['locale'] = $locale['locale_code'];
        $vars['locale_code'] = $locale['locale_code'];
        $vars['language'] = $locale['language_name'];
        $vars['language_name'] = $locale['language_name'];

        $source = (string) ($definition->template['source'] ?? '');
        if ($source === 'legacy_prompt_content') {
            $legacy = trim((string) ($metadata['legacy_compiled_prompt'] ?? ''));
            if ($legacy === '') {
                throw new TemplateRenderFailed(
                    'legacy_prompt_content requires metadata.legacy_compiled_prompt (SeoPrompt DB content).',
                );
            }

            $appended = $this->contracts()->appendToPrompt($legacy, $definition->outputContractKey());
            $metadata['output_contracts'] = $appended['contracts'];
            // SoT for PromptRunnerProviderAdapter::compileRequest — must include contract.
            $metadata['legacy_compiled_prompt'] = $appended['prompt'];

            $meta = [];
            foreach (array_keys($vars) as $key) {
                $meta[(string) $key] = in_array((string) $key, $definition->sensitiveInputFields, true)
                    ? '[redacted]'
                    : 'present';
            }

            return new RenderedPromptRequest(
                system: '',
                messages: [['role' => 'user', 'content' => $appended['prompt']]],
                model: $definition->model,
                modelSettings: $modelSettings,
                localeCode: $locale['locale_code'],
                languageName: $locale['language_name'],
                hookKey: $definition->key->value,
                hookVersion: $definition->version->toString(),
                redactedVariableMetadata: $meta,
                metadata: $metadata,
            );
        }

        $system = $this->renderString((string) ($definition->template['system'] ?? ''), $vars, $definition);
        $user = $this->renderString((string) ($definition->template['user'] ?? ''), $vars, $definition);
        if ($user === '' && isset($definition->template['template_key'])) {
            // Locale template key without body — still valid if user empty and system set.
            $user = $this->renderString('{{keyword}}{{title}}', $vars, $definition, allowPartial: true);
        }

        $appended = $this->contracts()->appendToPrompt($user, $definition->outputContractKey());
        $user = $appended['prompt'];
        $metadata['output_contracts'] = $appended['contracts'];

        $messages = [['role' => 'user', 'content' => $user]];
        $examples = $definition->template['examples'] ?? null;
        if (is_array($examples)) {
            foreach ($examples as $example) {
                if (! is_array($example)) {
                    continue;
                }
                $role = (string) ($example['role'] ?? 'assistant');
                $content = $this->renderString((string) ($example['content'] ?? ''), $vars, $definition);
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        $meta = [];
        foreach (array_keys($vars) as $key) {
            $meta[(string) $key] = in_array((string) $key, $definition->sensitiveInputFields, true)
                ? '[redacted]'
                : 'present';
        }

        return new RenderedPromptRequest(
            system: $system,
            messages: $messages,
            model: $definition->model,
            modelSettings: $modelSettings,
            localeCode: $locale['locale_code'],
            languageName: $locale['language_name'],
            hookKey: $definition->key->value,
            hookVersion: $definition->version->toString(),
            redactedVariableMetadata: $meta,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function renderString(
        string $template,
        array $variables,
        PromptHookDefinition $definition,
        bool $allowPartial = false,
    ): string {
        if (preg_match('/<\?php|eval\s*\(|include\s*\(|require\s*\(/i', $template) === 1) {
            throw new TemplateRenderFailed('Template must not contain executable PHP.');
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $matches) use ($variables, $definition, $allowPartial): string {
                $key = $matches[1];
                if (! array_key_exists($key, $variables)) {
                    if ($allowPartial || ! $definition->strictTemplateVariables) {
                        return '';
                    }
                    throw new TemplateRenderFailed("Missing template variable [{$key}]");
                }
                $value = $variables[$key];
                if ($value === null) {
                    return '';
                }
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }

                return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
            },
            $template,
        );
    }
}
