<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookInputSchema;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookKey;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookLimits;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookLocalePolicy;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookLoggingPolicy;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookModelConfig;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookOutputSchema;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookRetryPolicy;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookStatus;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookVersion;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookFailureCode;

/** Phase 1 manifest → canonical definition (experimental, pinned 0.1.0). */
final class PromptHookPhase1DualReadAdapter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function toCanonical(array $data, string $path = ''): PromptHookDefinition
    {
        $key = trim((string) ($data['key'] ?? ''));
        if ($key === '') {
            throw new PromptHookFailure(
                PromptHookFailureCode::DefinitionInvalid,
                'Phase 1 manifest missing key'.($path !== '' ? " in {$path}" : ''),
            );
        }

        if (isset($data['api_key']) || isset($data['secret'])) {
            throw new PromptHookFailure(
                PromptHookFailureCode::DefinitionInvalid,
                "Phase 1 manifest must not contain secrets: {$path}",
            );
        }

        $input = is_array($data['input'] ?? null) ? $data['input'] : [];
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        $output = is_array($data['output'] ?? null) ? $data['output'] : [];
        $model = is_array($data['model'] ?? null) ? $data['model'] : [];
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $template = is_array($data['template'] ?? null) ? $data['template'] : [];

        $inputSchema = [];
        foreach ($fields as $field => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            $inputSchema[(string) $field] = [
                'type' => $schema['type'] ?? 'string',
                'required' => (bool) ($schema['required'] ?? false),
                'nullable' => is_array($schema['type'] ?? null) && in_array('null', $schema['type'], true),
                'normalize' => $schema['normalize'] ?? [],
                'sources' => $schema['sources'] ?? [],
            ];
        }

        $versionRaw = $data['version'] ?? 1;
        // Experimental Phase 1 hooks pinned to 0.1.0 (not major=1 stable).
        $version = is_int($versionRaw) && $versionRaw === 1
            ? PromptHookVersion::parse('0.1.0')
            : PromptHookVersion::parse($versionRaw);

        return new PromptHookDefinition(
            key: new PromptHookKey($key),
            version: $version,
            status: PromptHookStatus::Experimental,
            name: (string) ($data['label_key'] ?? $key),
            description: (string) ($data['description_key'] ?? ''),
            model: new PromptHookModelConfig(
                provider: 'prompt_connection',
                name: 'configured',
                settings: [],
                capability: (string) ($model['capability'] ?? 'text'),
                structuredOutput: (bool) ($model['structured_output'] ?? false),
            ),
            locale: new PromptHookLocalePolicy(mode: 'site', fallback: 'en'),
            inputSchema: new PromptHookInputSchema($inputSchema),
            outputSchema: new PromptHookOutputSchema(
                type: (string) ($output['format'] ?? 'text'),
                normalize: array_values(array_map('strval', is_array($output['normalize'] ?? null) ? $output['normalize'] : [])),
                validation: is_array($output['validation'] ?? null) ? $output['validation'] : [],
            ),
            template: $template,
            retry: new PromptHookRetryPolicy(max: 0),
            logging: new PromptHookLoggingPolicy(storeFullPrompt: false, redactSensitive: true),
            limits: new PromptHookLimits,
            settingsSchema: $settings,
            sensitiveInputFields: [],
            metadata: [
                'source' => 'phase1_dual_read',
                'schema_version' => $data['schema_version'] ?? 1,
                'prompt_payload' => $input['prompt_payload'] ?? [],
            ],
            manifestPath: $path,
            strictTemplateVariables: true,
            settingsVisible: in_array($key, [
                'article.title_suggestion',
                'article.meta_description_suggestion',
            ], true),
            category: 'article_editor',
            presentation: [],
        );
    }
}
