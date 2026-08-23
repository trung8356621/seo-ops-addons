<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Illuminate\Support\HtmlString;

/**
 * Readonly Runtime Rules panel for Prompt Editor.
 * Sources Hook definition + Output Contract catalog — no compose, no user markdown.
 */
class PromptRuntimeRulesPresenter
{
    /** Top-level JSON keys handled as dedicated sections (or skipped as UI-only). */
    private const HANDLED_RAW_KEYS = [
        'spec_version',
        'key',
        'version',
        'status',
        'settings_visible',
        'category',
        'name',
        'description',
        'enabled',
        'classification',
        'legacy_binding',
        'model',
        'locale',
        'input_schema',
        'output_schema',
        'output_contract',
        'template',
        'validation',
        'retry',
        'logging',
        'permissions',
        'limits',
        'settings',
        'presentation',
        'metadata',
        'side_effects',
        'strict_template_variables',
    ];

    public function __construct(
        private readonly PromptHookEditorCatalog $catalog,
        private readonly PromptOutputContractResolver $contractResolver,
    ) {}

    public function renderHtml(string $hookKey, string $hookVersion = ''): HtmlString
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return new HtmlString(
                '<p class="text-sm text-gray-500 dark:text-gray-400">'
                .e($this->t(
                    'seo-content-ai::filament.prompt.runtime_rules_no_hook',
                    'No Hook selected. Runtime rules appear after you assign a Hook.',
                ))
                .'</p>',
            );
        }

        try {
            $definition = $hookVersion !== ''
                ? $this->catalog->find($hookKey, $hookVersion)
                : $this->catalog->latestPinnedOrFail($hookKey);
        } catch (\Throwable) {
            return new HtmlString(
                '<p class="text-sm text-amber-700 dark:text-amber-300">'
                .e($this->t(
                    'seo-content-ai::filament.prompt.runtime_rules_hook_missing',
                    'Hook definition not found. Runtime rules unavailable.',
                ))
                .'</p>',
            );
        }

        $raw = $this->loadRawSpec($definition);
        $sections = [];

        $contractHtml = $this->renderOutputContract($definition);
        if ($contractHtml !== '') {
            $sections[] = $contractHtml;
        }

        $validationHtml = $this->renderValidation($definition, $raw);
        if ($validationHtml !== '') {
            $sections[] = $validationHtml;
        }

        $runtimeHtml = $this->renderRuntimeModel($definition);
        if ($runtimeHtml !== '') {
            $sections[] = $runtimeHtml;
        }

        $sourceHtml = $this->renderSourceRules($definition);
        if ($sourceHtml !== '') {
            $sections[] = $sourceHtml;
        }

        $inputHtml = $this->renderInputSchema($definition);
        if ($inputHtml !== '') {
            $sections[] = $inputHtml;
        }

        $templateHtml = $this->renderTemplate($definition);
        if ($templateHtml !== '') {
            $sections[] = $templateHtml;
        }

        $loggingHtml = $this->renderLogging($definition, $raw);
        if ($loggingHtml !== '') {
            $sections[] = $loggingHtml;
        }

        $limitsHtml = $this->renderLimits($definition, $raw);
        if ($limitsHtml !== '') {
            $sections[] = $limitsHtml;
        }

        $permissionsHtml = $this->renderPermissions($raw);
        if ($permissionsHtml !== '') {
            $sections[] = $permissionsHtml;
        }

        $metadataHtml = $this->renderMetadataExtras($definition);
        if ($metadataHtml !== '') {
            $sections[] = $metadataHtml;
        }

        $sideEffectsHtml = $this->renderSideEffects($raw);
        if ($sideEffectsHtml !== '') {
            $sections[] = $sideEffectsHtml;
        }

        $extraHtml = $this->renderLeftoverRawKeys($raw);
        if ($extraHtml !== '') {
            $sections[] = $extraHtml;
        }

        if ($sections === []) {
            return new HtmlString(
                '<p class="text-sm text-gray-500 dark:text-gray-400">'
                .e($this->t(
                    'seo-content-ai::filament.prompt.runtime_rules_empty',
                    'This Hook has no built-in runtime rules to display.',
                ))
                .'</p>',
            );
        }

        $hint = '<p class="text-xs text-gray-500 dark:text-gray-400 mb-3">'
            .e($this->t(
                'seo-content-ai::filament.prompt.runtime_rules_readonly_hint',
                'Readonly. Applied by runtime — not part of your Prompt markdown.',
            ))
            .'</p>';

        return new HtmlString($hint.'<div class="space-y-3">'.implode('', $sections).'</div>');
    }

    private function renderOutputContract(PromptHookDefinition $definition): string
    {
        $key = $definition->outputContractKey();
        if ($key === null) {
            return '';
        }

        try {
            $resolved = $this->contractResolver->resolve($key);
        } catch (\Throwable) {
            return $this->section(
                $this->t('seo-content-ai::filament.prompt.runtime_rules_output_contract', 'Output Contract'),
                '<p class="text-sm text-amber-700 dark:text-amber-300">'
                .e($this->t(
                    'seo-content-ai::filament.prompt.runtime_rules_contract_missing',
                    'Contract :key not found.',
                    ['key' => $key],
                ))
                .'</p>',
            );
        }

        $meta = [];
        foreach ($resolved['contracts'] as $row) {
            $meta[] = e((string) ($row['key'] ?? '')).'@'.e((string) ($row['version'] ?? ''));
        }
        $headerExtra = $meta !== []
            ? '<p class="text-xs font-mono text-gray-500 dark:text-gray-400 mb-2">'.implode(', ', $meta).'</p>'
            : '';

        $body = trim((string) ($resolved['text'] ?? ''));
        if ($body === '') {
            return '';
        }

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_output_contract', 'Output Contract'),
            $headerExtra.$this->pre($body),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function renderValidation(PromptHookDefinition $definition, array $raw): string
    {
        $lines = [];

        $outputType = trim((string) ($definition->outputSchema->type ?? ''));
        if ($outputType !== '') {
            $lines[] = 'output_type: '.$outputType;
        }

        foreach ($definition->outputSchema->validation as $name => $value) {
            $lines[] = (string) $name.': '.$this->scalar($value);
        }

        foreach ($definition->outputSchema->normalize as $step) {
            $lines[] = 'normalize: '.(string) $step;
        }

        $topValidation = is_array($raw['validation'] ?? null) ? $raw['validation'] : [];
        foreach ($topValidation as $name => $value) {
            $line = 'validation.'.(string) $name.': '.$this->scalar($value);
            if (! in_array($line, $lines, true) && ! in_array((string) $name.': '.$this->scalar($value), $lines, true)) {
                $lines[] = $line;
            }
        }

        $lines[] = 'retry.max: '.(string) $definition->retry->max;
        if ($definition->retry->on !== []) {
            $lines[] = 'retry.on: '.implode(', ', $definition->retry->on);
        }

        if ($definition->outputSchema->sections !== []) {
            $lines[] = 'output_sections: '.count($definition->outputSchema->sections);
            foreach ($definition->outputSchema->sections as $i => $section) {
                if (! is_array($section)) {
                    continue;
                }
                $port = (string) ($section['output_port'] ?? $section['name'] ?? ('#'.$i));
                $lines[] = '  - '.$port;
            }
        }

        if ($lines === []) {
            return '';
        }

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_validation', 'Validation'),
            $this->list($lines),
        );
    }

    private function renderRuntimeModel(PromptHookDefinition $definition): string
    {
        $lines = [
            'provider: '.$definition->model->provider,
            'name: '.$definition->model->name,
            'capability: '.$definition->model->capability,
            'structured_output: '.$this->boolLabel($definition->model->structuredOutput),
        ];

        foreach ($definition->model->settings as $name => $value) {
            $lines[] = (string) $name.': '.$this->scalar($value);
        }

        $lines[] = 'locale.mode: '.$definition->locale->mode;
        $lines[] = 'locale.fallback: '.$definition->locale->fallback;
        if ($definition->locale->fixed !== null && $definition->locale->fixed !== '') {
            $lines[] = 'locale.fixed: '.$definition->locale->fixed;
        }

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_runtime', 'Runtime'),
            $this->list($lines),
        );
    }

    private function renderSourceRules(PromptHookDefinition $definition): string
    {
        $rules = $definition->metadata['source_type_rules'] ?? null;
        if (! is_array($rules) || $rules === []) {
            return '';
        }

        $lines = [];
        foreach ($rules as $source => $rule) {
            $lines[] = (string) $source.': '.$this->scalar($rule);
        }

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_source', 'Source Rules'),
            $this->list($lines),
        );
    }

    private function renderInputSchema(PromptHookDefinition $definition): string
    {
        $fields = $definition->inputSchema->fields;
        if ($fields === []) {
            return '';
        }

        $lines = [];
        foreach ($fields as $name => $schema) {
            if (! is_array($schema)) {
                $lines[] = (string) $name;
                continue;
            }
            $parts = [(string) $name];
            if (isset($schema['type'])) {
                $parts[] = 'type='.$this->scalar($schema['type']);
            }
            if (array_key_exists('required', $schema)) {
                $parts[] = ((bool) $schema['required']) ? 'required' : 'optional';
            }
            $sources = $schema['allowed_source'] ?? null;
            if (is_array($sources) && $sources !== []) {
                $parts[] = 'source='.implode('|', array_map('strval', $sources));
            }
            if (isset($schema['max_length'])) {
                $parts[] = 'max_length='.$this->scalar($schema['max_length']);
            }
            if (isset($schema['minimum'])) {
                $parts[] = 'min='.$this->scalar($schema['minimum']);
            }
            if (isset($schema['maximum'])) {
                $parts[] = 'max='.$this->scalar($schema['maximum']);
            }
            $lines[] = implode(' · ', $parts);
        }

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_inputs', 'Input Schema'),
            $this->list($lines),
        );
    }

    private function renderTemplate(PromptHookDefinition $definition): string
    {
        $template = $definition->template;
        if ($template === []) {
            return '';
        }

        $lines = [];
        $source = trim((string) ($template['source'] ?? ''));
        if ($source !== '') {
            $lines[] = 'source: '.$source;
        }

        $system = trim((string) ($template['system'] ?? ''));
        $user = trim((string) ($template['user'] ?? ''));

        $body = '';
        if ($system !== '') {
            $body .= '<div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">system</div>'
                .$this->pre($system);
        }
        if ($user !== '') {
            $body .= '<div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mt-2 mb-1">user</div>'
                .$this->pre($user);
        }

        if ($lines === [] && $body === '') {
            return '';
        }

        $list = $lines !== [] ? $this->list($lines) : '';

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_template', 'Hook Template'),
            $list.$body,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function renderLogging(PromptHookDefinition $definition, array $raw): string
    {
        $lines = [
            'store_full_prompt: '.$this->boolLabel($definition->logging->storeFullPrompt),
            'redact_sensitive: '.$this->boolLabel($definition->logging->redactSensitive),
        ];

        $rawLogging = is_array($raw['logging'] ?? null) ? $raw['logging'] : [];
        foreach ($rawLogging as $name => $value) {
            $key = (string) $name;
            if (in_array($key, ['store_full_prompt', 'redact_sensitive'], true)) {
                continue;
            }
            $lines[] = $key.': '.$this->scalar($value);
        }

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_logging', 'Logging'),
            $this->list($lines),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function renderLimits(PromptHookDefinition $definition, array $raw): string
    {
        $rawLimits = is_array($raw['limits'] ?? null) ? $raw['limits'] : [];
        $lines = [];

        if ($rawLimits !== []) {
            foreach ($rawLimits as $name => $value) {
                $lines[] = (string) $name.': '.$this->scalar($value);
            }
        } else {
            $lines[] = 'max_previous_outputs_total_bytes: '.(string) $definition->limits->maxPreviousOutputsTotalBytes;
            $lines[] = 'max_previous_outputs_item_bytes: '.(string) $definition->limits->maxPreviousOutputsItemBytes;
            $lines[] = 'max_previous_outputs_items: '.(string) $definition->limits->maxPreviousOutputsItems;
        }

        $help = $this->t(
            'seo-content-ai::filament.prompt.runtime_rules_limits_help',
            'max_previous_outputs_* limits prior outputs in context — not comment_count.',
        );

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_limits', 'Limits (context)'),
            $this->list($lines).'<p class="mt-2 text-xs text-gray-500 dark:text-gray-400">'.e($help).'</p>',
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function renderPermissions(array $raw): string
    {
        $permissions = $raw['permissions'] ?? null;
        if (! is_array($permissions) || $permissions === []) {
            return '';
        }

        $lines = [];
        foreach ($permissions as $name => $value) {
            $lines[] = (string) $name.': '.$this->scalar($value);
        }

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_permissions', 'Permissions'),
            $this->list($lines),
        );
    }

    private function renderMetadataExtras(PromptHookDefinition $definition): string
    {
        $meta = $definition->metadata;
        if ($meta === []) {
            return '';
        }

        $lines = [];
        foreach ($meta as $name => $value) {
            if ((string) $name === 'source_type_rules') {
                continue;
            }
            $lines[] = (string) $name.': '.$this->scalar($value);
        }

        if ($lines === []) {
            return '';
        }

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_metadata', 'Metadata'),
            $this->list($lines),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function renderSideEffects(array $raw): string
    {
        $effects = $raw['side_effects'] ?? null;
        if (! is_array($effects) || $effects === []) {
            return '';
        }

        $lines = array_map(fn (mixed $item): string => $this->scalar($item), $effects);

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_side_effects', 'Side Effects'),
            $this->list($lines),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function renderLeftoverRawKeys(array $raw): string
    {
        $lines = [];
        foreach ($raw as $key => $value) {
            if (in_array((string) $key, self::HANDLED_RAW_KEYS, true)) {
                continue;
            }
            $lines[] = (string) $key.': '.$this->scalar($value);
        }

        if ($lines === []) {
            return '';
        }

        return $this->section(
            $this->t('seo-content-ai::filament.prompt.runtime_rules_other', 'Other Runtime Fields'),
            $this->list($lines),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadRawSpec(PromptHookDefinition $definition): array
    {
        $path = trim($definition->manifestPath);
        if ($path === '' || ! is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function section(string $title, string $body): string
    {
        return '<div class="rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-900/30 p-3 space-y-2">'
            .'<div class="text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">'
            .e($title)
            .'</div>'
            .$body
            .'</div>';
    }

    /**
     * @param  list<string>  $lines
     */
    private function list(array $lines): string
    {
        $html = '<ul class="text-sm text-gray-700 dark:text-gray-200 space-y-1 list-none pl-0 font-mono">';
        foreach ($lines as $line) {
            $html .= '<li>'.e($line).'</li>';
        }

        return $html.'</ul>';
    }

    private function pre(string $body): string
    {
        return '<pre class="seo-prompt-runtime-rules max-h-[28rem] overflow-y-auto whitespace-pre-wrap text-sm font-mono rounded-md bg-white dark:bg-gray-950/50 p-3 border border-gray-200 dark:border-gray-700">'
            .e($body)
            .'</pre>';
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $this->boolLabel($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return '[array]';
            }
            if (mb_strlen($encoded) > 500) {
                return mb_substr($encoded, 0, 500).'…';
            }

            return $encoded;
        }

        return (string) $value;
    }

    private function boolLabel(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    private function t(string $key, string $fallback, array $replace = []): string
    {
        try {
            if (! function_exists('app') || ! app()->bound('translator')) {
                $text = $fallback;
                foreach ($replace as $search => $value) {
                    $text = str_replace(':'.$search, (string) $value, $text);
                }

                return $text;
            }
            $translated = __($key, $replace);
            if (is_array($translated)) {
                return $fallback;
            }
            $text = trim((string) $translated);

            return $text !== '' ? $text : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
