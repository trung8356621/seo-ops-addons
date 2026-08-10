<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\PromptOwnership;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;

/**
 * Public view-model for Hook guidance in Settings / Prompt Edit.
 * Presentation metadata only — does not affect runtime resolve.
 *
 * @phpstan-type PresentationSection array{
 *     key: string,
 *     label: string,
 *     items: list<string|array{key?: string, label: string, required?: bool}>
 * }
 * @phpstan-type PresentationView array{
 *     hook_key: string,
 *     label: string,
 *     description: string,
 *     content_mode: string,
 *     uses_prompt_markdown: bool,
 *     sections: list<PresentationSection>,
 *     default_instructions_title: string,
 *     output_format_title: string,
 *     input_data_title: string,
 *     notes_title: string,
 *     default_instructions: list<string>,
 *     output_format: list<string>,
 *     notes: list<string>,
 *     inputs: list<array{key: string, label: string, required: bool}>
 * }
 */
final class PromptHookPresentationService
{
    public const CONTENT_MODE_LEGACY_PROMPT = 'legacy_prompt_content';

    public const CONTENT_MODE_INLINE = 'inline_template';

    public function __construct(
        private readonly PromptHookEditorCatalog $catalog,
    ) {}

    /**
     * @return PresentationView|null
     */
    public function forHook(string $hookKey): ?array
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return null;
        }

        try {
            $definition = $this->catalog->latestPinnedOrFail($hookKey);
        } catch (\Throwable) {
            return null;
        }

        return $this->fromDefinition($definition);
    }

    /**
     * @return PresentationView
     */
    public function fromDefinition(PromptHookDefinition $definition): array
    {
        $presentation = is_array($definition->presentation) ? $definition->presentation : [];
        $label = $this->friendlyLabel($definition);
        $description = $this->friendlyDescription($definition);
        $contentMode = $this->resolveContentMode($definition);

        $instructionsTitle = $this->stringOr(
            $presentation['default_instructions_title'] ?? null,
            $this->safeTranslate(
                'seo-content-ai::filament.prompt.hook_default_instructions_title',
                'Default guidance from Hook',
            ),
        );
        $outputTitle = $this->stringOr(
            $presentation['output_format_title'] ?? null,
            $this->safeTranslate(
                'seo-content-ai::filament.prompt.hook_output_format_title',
                'Expected output format',
            ),
        );
        $inputTitle = $this->stringOr(
            $presentation['input_data_title'] ?? null,
            $this->safeTranslate(
                'seo-content-ai::filament.prompt.hook_input_data_title',
                'Data passed in',
            ),
        );
        $notesTitle = $this->stringOr(
            $presentation['notes_title'] ?? null,
            $this->safeTranslate(
                'seo-content-ai::filament.prompt.hook_notes_title',
                'Notes',
            ),
        );

        $instructions = $this->resolveLocalizedList(
            $definition->key->value,
            'default_instructions',
            $presentation['default_instructions'] ?? null,
        );
        $outputFormat = $this->resolveLocalizedList(
            $definition->key->value,
            'output_format',
            $presentation['output_format'] ?? null,
        );
        $notes = $this->resolveLocalizedList(
            $definition->key->value,
            'notes',
            $presentation['notes'] ?? null,
        );
        $inputs = $this->resolveInputs($definition, $presentation);

        $sections = [];
        if ($instructions !== []) {
            $sections[] = [
                'key' => 'default_instructions',
                'label' => $instructionsTitle,
                'items' => $instructions,
            ];
        }
        if ($outputFormat !== []) {
            $sections[] = [
                'key' => 'output_format',
                'label' => $outputTitle,
                'items' => $outputFormat,
            ];
        }
        if ($inputs !== []) {
            $sections[] = [
                'key' => 'runtime_inputs',
                'label' => $inputTitle,
                'items' => $inputs,
            ];
        }
        if ($notes !== []) {
            $sections[] = [
                'key' => 'notes',
                'label' => $notesTitle,
                'items' => $notes,
            ];
        }

        return [
            'hook_key' => $definition->key->value,
            'label' => $label,
            'description' => $description,
            'content_mode' => $contentMode,
            'uses_prompt_markdown' => $contentMode === self::CONTENT_MODE_LEGACY_PROMPT,
            'sections' => $sections,
            'default_instructions_title' => $instructionsTitle,
            'output_format_title' => $outputTitle,
            'input_data_title' => $inputTitle,
            'notes_title' => $notesTitle,
            'default_instructions' => $instructions,
            'output_format' => $outputFormat,
            'notes' => $notes,
            'inputs' => $inputs,
        ];
    }

    /**
     * @param  list<string>  $lines
     */
    public function formatBulletHtml(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        $items = array_map(
            static fn (string $line): string => '<li>'.e($line).'</li>',
            $lines,
        );

        return '<ul class="list-disc pl-5 space-y-1 text-sm text-gray-700 dark:text-gray-200">'.implode('', $items).'</ul>';
    }

    /**
     * @param  list<array{key: string, label: string, required: bool}>  $inputs
     */
    public function formatInputsHtml(array $inputs): string
    {
        if ($inputs === []) {
            return '';
        }

        $items = [];
        foreach ($inputs as $input) {
            $label = e($input['label']);
            $placeholder = e('{{'.$input['key'].'}}');
            $suffix = '';
            if (! $input['required']) {
                $suffix = ' <span class="text-xs text-gray-500">('
                    .e($this->safeTranslate('seo-content-ai::filament.prompt.hook_input_optional', 'optional'))
                    .')</span>';
            }
            $items[] = '<li><span class="font-medium">'.$label.'</span>'
                .' <span class="text-gray-400">—</span> '
                .'<code class="text-xs">'.$placeholder.'</code>'
                .$suffix.'</li>';
        }

        return '<ul class="list-disc pl-5 space-y-1 text-sm text-gray-700 dark:text-gray-200">'.implode('', $items).'</ul>';
    }

    /**
     * Render all non-empty presentation sections as HTML blocks.
     *
     * @param  PresentationView  $view
     */
    public function formatSectionsHtml(array $view): string
    {
        $blocks = [];
        foreach ($view['sections'] as $section) {
            $items = $section['items'] ?? [];
            if ($items === []) {
                continue;
            }
            $label = e((string) ($section['label'] ?? ''));
            $key = (string) ($section['key'] ?? '');
            if ($key === 'runtime_inputs') {
                /** @var list<array{key: string, label: string, required: bool}> $typed */
                $typed = [];
                foreach ($items as $item) {
                    if (is_array($item) && isset($item['label'])) {
                        $typed[] = [
                            'key' => (string) ($item['key'] ?? ''),
                            'label' => (string) $item['label'],
                            'required' => (bool) ($item['required'] ?? false),
                        ];
                    }
                }
                $body = $this->formatInputsHtml($typed);
            } else {
                $lines = [];
                foreach ($items as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $lines[] = trim($item);
                    }
                }
                $body = $this->formatBulletHtml($lines);
            }
            if ($body === '') {
                continue;
            }
            $blocks[] = '<div class="space-y-1"><div class="text-sm font-medium text-gray-900 dark:text-gray-100">'
                .$label
                .'</div>'.$body.'</div>';
        }

        if ($blocks === []) {
            return '';
        }

        return '<div class="space-y-4">'.implode('', $blocks).'</div>';
    }

    private function resolveContentMode(PromptHookDefinition $definition): string
    {
        $source = trim((string) ($definition->template['source'] ?? ''));
        $mode = trim((string) ($definition->template['mode'] ?? ''));
        if ($source === self::CONTENT_MODE_LEGACY_PROMPT || $mode === self::CONTENT_MODE_LEGACY_PROMPT) {
            return self::CONTENT_MODE_LEGACY_PROMPT;
        }

        return self::CONTENT_MODE_INLINE;
    }

    private function friendlyLabel(PromptHookDefinition $definition): string
    {
        $fallback = $definition->name !== '' ? $definition->name : $definition->key->value;
        $langKey = 'seo-content-ai::prompt_hooks.'.$this->langSlug($definition->key->value).'.label';
        $translated = $this->safeTranslate($langKey, $fallback);

        return ($translated !== $langKey && $translated !== '') ? $translated : $fallback;
    }

    private function friendlyDescription(PromptHookDefinition $definition): string
    {
        $presentation = is_array($definition->presentation) ? $definition->presentation : [];
        $fromPresentation = trim((string) ($presentation['description'] ?? ''));
        $fallback = $fromPresentation !== '' ? $fromPresentation : $definition->description;
        $langKey = 'seo-content-ai::prompt_hooks.'.$this->langSlug($definition->key->value).'.description';
        $translated = $this->safeTranslate($langKey, $fallback);

        return ($translated !== $langKey && $translated !== '') ? $translated : $fallback;
    }

    /**
     * @return list<string>
     */
    private function resolveLocalizedList(string $hookKey, string $field, mixed $fallback): array
    {
        $langKey = 'seo-content-ai::prompt_hooks.'.$this->langSlug($hookKey).'.presentation.'.$field;
        try {
            if (function_exists('app') && app()->bound('translator')) {
                $translated = __($langKey);
                if (is_array($translated)) {
                    $list = $this->stringList($translated);
                    if ($list !== []) {
                        return $list;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $this->stringList($fallback);
    }

    /**
     * @param  array<string, mixed>  $presentation
     * @return list<array{key: string, label: string, required: bool}>
     */
    private function resolveInputs(PromptHookDefinition $definition, array $presentation): array
    {
        $declared = is_array($presentation['variables'] ?? null) ? $presentation['variables'] : [];
        if ($declared !== []) {
            $rows = [];
            foreach ($declared as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $key = trim((string) ($row['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $rows[] = [
                    'key' => $key,
                    'label' => $this->resolveVariableLabel($key, (string) ($row['label'] ?? '')),
                    'required' => (bool) ($row['required'] ?? false),
                ];
            }

            return $rows;
        }

        $rows = [];
        foreach ($definition->inputSchema->fields as $field => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            $key = (string) $field;
            $rows[] = [
                'key' => $key,
                'label' => $this->resolveVariableLabel($key, (string) ($schema['label'] ?? '')),
                'required' => (bool) ($schema['required'] ?? false),
            ];
        }

        return $rows;
    }

    private function resolveVariableLabel(string $key, string $declaredLabel): string
    {
        $langKey = 'seo-content-ai::prompt_hooks.variables.'.$key;
        $translated = $this->safeTranslate($langKey, '');
        if ($translated !== '' && $translated !== $langKey) {
            return $translated;
        }

        $declared = trim($declaredLabel);
        if ($declared !== '') {
            return $declared;
        }

        return $this->humanizeKey($key);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                continue;
            }
            $line = trim((string) $item);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function stringOr(mixed $value, string $fallback): string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : $fallback;
    }

    private function safeTranslate(string $key, string $fallback): string
    {
        try {
            if (! function_exists('app') || ! app()->bound('translator')) {
                return $fallback !== '' ? $fallback : $key;
            }

            $translated = __($key);
            if (is_array($translated)) {
                return $fallback !== '' ? $fallback : $key;
            }
            $text = trim((string) $translated);

            return $text !== '' ? $text : ($fallback !== '' ? $fallback : $key);
        } catch (\Throwable) {
            return $fallback !== '' ? $fallback : $key;
        }
    }

    private function humanizeKey(string $key): string
    {
        $text = str_replace(['_', '.'], ' ', $key);

        return ucwords(trim($text));
    }

    private function langSlug(string $hookKey): string
    {
        return str_replace('.', '_', $hookKey);
    }
}
