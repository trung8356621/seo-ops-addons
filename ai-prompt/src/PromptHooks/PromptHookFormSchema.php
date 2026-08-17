<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookStatus;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\DefinitionNotFound;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\VersionNotFound;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Filament form: Hook select from canonical RuntimeRegistry catalog.
 */
final class PromptHookFormSchema
{
    /**
     * Hook selector + settings only (short fields).
     *
     * @return list<Forms\Components\Component>
     */
    public static function section(): array
    {
        return [
            Forms\Components\Section::make(__('seo-content-ai::filament.prompt.hook_section'))
                ->description(__('seo-content-ai::filament.prompt.hook_section_description'))
                ->schema([
                    Forms\Components\Placeholder::make('hook_quick_split_runtime_note')
                        ->label('')
                        ->content(__('seo-content-ai::filament.prompt.hook_quick_split_note'))
                        ->visible(fn (Get $get): bool => ImageToolType::fromMixed($get('tools') ?? 'default')->isImagePipeline()
                            && (bool) $get('settings.post_processing.split_enabled'))
                        ->extraAttributes(['class' => 'text-sm text-gray-600 dark:text-gray-400']),

                    Forms\Components\Select::make('hook_key')
                        ->label(__('seo-content-ai::filament.prompt.hook'))
                        ->helperText(__('seo-content-ai::filament.prompt.hook_helper'))
                        ->options(function (PromptHookEditorCatalog $catalog, Get $get): array {
                            return array_merge(
                                ['' => (string) __('seo-content-ai::prompt_hooks.none')],
                                $catalog->selectOptionsForEditing((string) ($get('hook_key') ?? '')),
                            );
                        })
                        ->placeholder(__('seo-content-ai::prompt_hooks.none'))
                        ->nullable()
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                            self::onHookChanged($state, $set, $get);
                        }),

                    Forms\Components\Hidden::make('hook_version'),

                    Forms\Components\Placeholder::make('hook_legacy_rewrite_warning')
                        ->label('')
                        ->content(__('seo-content-ai::filament.prompt.hook_legacy_rewrite_warning'))
                        ->visible(fn (Get $get, PromptHookEditorCatalog $catalog): bool => $catalog->isLegacyCompatibilityHook(
                            (string) ($get('hook_key') ?? ''),
                        )),

                    Forms\Components\Placeholder::make('hook_experimental_warning')
                        ->label('')
                        ->content(fn (Get $get): string => self::experimentalWarning((string) ($get('hook_key') ?? ''), (string) ($get('hook_version') ?? '')))
                        ->visible(fn (Get $get): bool => self::isExperimentalSelected((string) ($get('hook_key') ?? ''), (string) ($get('hook_version') ?? ''))),

                    Forms\Components\Group::make()
                        ->schema(fn (Get $get): array => self::settingsFields(
                            (string) ($get('hook_key') ?? ''),
                            (string) ($get('hook_version') ?? ''),
                        ))
                        ->visible(fn (Get $get): bool => filled($get('hook_key'))),
                ]),
        ];
    }

    /**
     * Collapsed Hook guidance under composed preview.
     *
     * @return list<Forms\Components\Component>
     */
    public static function guidanceSection(): array
    {
        return [
            Forms\Components\Section::make(__('seo-content-ai::filament.prompt.hook_guidance_section'))
                ->description(__('seo-content-ai::filament.prompt.hook_guidance_section_description'))
                ->schema([
                    Forms\Components\Placeholder::make('hook_description_display')
                        ->label(__('seo-content-ai::filament.prompt.hook_description'))
                        ->content(fn (Get $get): string => self::presentationFor((string) ($get('hook_key') ?? ''))['description']
                            ?? self::hookDescription(
                                (string) ($get('hook_key') ?? ''),
                                (string) ($get('hook_version') ?? ''),
                            ))
                        ->visible(fn (Get $get): bool => filled($get('hook_key'))),

                    Forms\Components\Placeholder::make('hook_default_instructions_display')
                        ->label(fn (Get $get): string => (string) (self::presentationFor((string) ($get('hook_key') ?? ''))['default_instructions_title']
                            ?? __('seo-content-ai::filament.prompt.hook_default_instructions_title')))
                        ->content(fn (Get $get): HtmlString => self::presentationInstructionsHtml((string) ($get('hook_key') ?? '')))
                        ->visible(fn (Get $get): bool => self::presentationHasInstructions((string) ($get('hook_key') ?? ''))),

                    Forms\Components\Placeholder::make('hook_output_format_display')
                        ->label(fn (Get $get): string => (string) (self::presentationFor((string) ($get('hook_key') ?? ''))['output_format_title']
                            ?? __('seo-content-ai::filament.prompt.hook_output_format_title')))
                        ->content(fn (Get $get): HtmlString => self::presentationOutputHtml((string) ($get('hook_key') ?? '')))
                        ->visible(fn (Get $get): bool => self::presentationHasOutput((string) ($get('hook_key') ?? ''))),

                    Forms\Components\Placeholder::make('hook_input_data_display')
                        ->label(fn (Get $get): string => (string) (self::presentationFor((string) ($get('hook_key') ?? ''))['input_data_title']
                            ?? __('seo-content-ai::filament.prompt.hook_input_data_title')))
                        ->content(fn (Get $get): HtmlString => self::presentationInputsHtml((string) ($get('hook_key') ?? '')))
                        ->visible(fn (Get $get): bool => self::presentationHasInputs((string) ($get('hook_key') ?? ''))),

                    Forms\Components\Placeholder::make('hook_notes_display')
                        ->label(fn (Get $get): string => (string) (self::presentationFor((string) ($get('hook_key') ?? ''))['notes_title']
                            ?? __('seo-content-ai::filament.prompt.hook_notes_title')))
                        ->content(fn (Get $get): HtmlString => self::presentationNotesHtml((string) ($get('hook_key') ?? '')))
                        ->visible(fn (Get $get): bool => self::presentationHasNotes((string) ($get('hook_key') ?? ''))),
                ])
                ->collapsible()
                ->collapsed()
                ->visible(fn (Get $get): bool => filled($get('hook_key'))
                    && self::hasAnyGuidance((string) ($get('hook_key') ?? ''))),
        ];
    }

    public static function composedPreviewHtml(Get $get): HtmlString
    {
        return app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptCompositionSummaryPresenter::class)
            ->renderHtml(
                (string) ($get('hook_key') ?? ''),
                (string) ($get('hook_version') ?? ''),
                (string) ($get('markdown_content') ?? ''),
                is_array($get('hook_settings')) ? $get('hook_settings') : [],
                (bool) ($get('composed_preview_expanded') ?? false),
            );
    }

    private static function hasAnyGuidance(string $hookKey): bool
    {
        return self::presentationHasInstructions($hookKey)
            || self::presentationHasOutput($hookKey)
            || self::presentationHasInputs($hookKey)
            || self::presentationHasNotes($hookKey)
            || filled(self::presentationFor($hookKey)['description'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeForSave(array $data): array
    {
        $hookKey = trim((string) ($data['hook_key'] ?? ''));
        if ($hookKey === '') {
            $data['hook_key'] = null;
            $data['hook_version'] = null;
            $data['hook_settings'] = null;

            return $data;
        }

        $catalog = app(PromptHookEditorCatalog::class);
        $version = trim((string) ($data['hook_version'] ?? ''));
        try {
            $definition = self::resolveDefinitionForSave($catalog, $hookKey, $version);
        } catch (DefinitionNotFound|\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'hook_key' => $exception->getMessage(),
            ]);
        }

        if (($definition->model->capability ?? 'text') === 'text'
            && ImageToolType::fromMixed($data['tools'] ?? 'default')->isImagePipeline()
        ) {
            throw ValidationException::withMessages([
                'tools' => __('seo-content-ai::filament.prompt.hook_requires_text_tool'),
            ]);
        }

        $stored = is_array($data['hook_settings'] ?? null) ? $data['hook_settings'] : [];
        $allowedKeys = array_map('strval', array_keys($definition->settingsSchema));
        if ($allowedKeys !== []) {
            $stored = array_intersect_key($stored, array_flip($allowedKeys));
        } else {
            $stored = [];
        }
        $resolved = app(PromptHookRuntimeSettingsResolver::class)->resolve($definition, $stored, []);
        $data['hook_settings'] = $resolved['hook'] !== [] ? $resolved['hook'] : null;
        $data['hook_key'] = $definition->key->value;
        $data['hook_version'] = $definition->version->toString();

        return $data;
    }

    private static function onHookChanged(?string $state, Set $set, Get $get): void
    {
        $hookKey = trim((string) $state);
        if ($hookKey === '') {
            $set('hook_key', null);
            $set('hook_version', null);
            $set('hook_settings', null);

            return;
        }

        try {
            $definition = app(PromptHookEditorCatalog::class)->latestPinnedOrFail($hookKey);
        } catch (\Throwable) {
            $set('hook_key', null);
            $set('hook_version', null);
            $set('hook_settings', null);

            return;
        }

        $set('hook_version', $definition->version->toString());

        $current = is_array($get('hook_settings')) ? $get('hook_settings') : [];
        $allowedKeys = array_map('strval', array_keys($definition->settingsSchema));
        if ($allowedKeys !== []) {
            $current = array_intersect_key($current, array_flip($allowedKeys));
        } else {
            $current = [];
        }
        $resolved = app(PromptHookRuntimeSettingsResolver::class)->resolve($definition, $current, []);
        $set('hook_settings', $resolved['hook']);

        if (($definition->model->capability ?? 'text') === 'text'
            && ImageToolType::fromMixed($get('tools'))->isImagePipeline()
        ) {
            $set('tools', ImageToolType::Default->value);
        }

        if (($definition->model->capability ?? 'text') === 'image'
            && ! ImageToolType::fromMixed($get('tools') ?? 'default')->isImagePipeline()
        ) {
            $set('tools', ImageToolType::Image->value);
        }
    }

    private static function resolveDefinition(string $hookKey, string $version): ?PromptHookDefinition
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return null;
        }

        try {
            return self::resolveDefinitionForSave(
                self::editorCatalog(),
                $hookKey,
                trim($version),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Prefer container catalog; fall back to filesystem registry for pure PHPUnit.
     */
    private static function editorCatalog(): PromptHookEditorCatalog
    {
        try {
            if (function_exists('app') && app()->bound(PromptHookEditorCatalog::class)) {
                return app(PromptHookEditorCatalog::class);
            }
        } catch (\Throwable) {
        }

        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );

        return new PromptHookEditorCatalog(new PromptHookRuntimeRegistry($loader));
    }

    /**
     * Resolve hook for editor/save. Legacy int version (vd. "1" → 1.0.0) fallback latest pinned.
     *
     * @throws DefinitionNotFound
     * @throws \InvalidArgumentException
     */
    private static function resolveDefinitionForSave(
        PromptHookEditorCatalog $catalog,
        string $hookKey,
        string $version,
    ): PromptHookDefinition {
        if ($version === '') {
            return $catalog->latestPinnedOrFail($hookKey);
        }

        try {
            return $catalog->find($hookKey, $version);
        } catch (VersionNotFound) {
            return $catalog->latestPinnedOrFail($hookKey);
        }
    }

    private static function isExperimentalSelected(string $hookKey, string $version): bool
    {
        $definition = self::resolveDefinition($hookKey, $version);

        return $definition !== null && $definition->status === PromptHookStatus::Experimental;
    }

    private static function experimentalWarning(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        $ver = $definition?->version->toString() ?? ($version !== '' ? $version : '0.1.0');

        return (string) __('seo-content-ai::prompt_hooks.experimental_warning', ['version' => $ver]);
    }

    private static function hookDescription(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition === null) {
            return '—';
        }

        foreach (app(PromptHookEditorCatalog::class)->optionsForTextPromptBlock() as $row) {
            if ($row['hook_key'] === $definition->key->value && $row['version'] === $definition->version->toString()) {
                return $row['description'] !== '' ? $row['description'] : '—';
            }
        }

        return $definition->description !== '' ? $definition->description : '—';
    }

    private static function hookContractSummary(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition === null) {
            return '—';
        }

        $required = [];
        $optional = [];
        foreach ($definition->inputSchema->fields as $field => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            if (($schema['required'] ?? false) === true) {
                $required[] = $field;
            } else {
                $optional[] = $field;
            }
        }

        return implode("\n", [
            __('seo-content-ai::filament.prompt.hook_contract_required').': '
                .($required !== [] ? implode(', ', $required) : '—'),
            __('seo-content-ai::filament.prompt.hook_contract_optional').': '
                .($optional !== [] ? implode(', ', $optional) : '—'),
            __('seo-content-ai::filament.prompt.hook_contract_output').': '.$definition->outputSchema->type,
            'output_contract: '.($definition->outputContractKey() ?? '—'),
            __('seo-content-ai::filament.prompt.hook_contract_capability').': '.($definition->model->capability ?? 'text'),
            'version: '.$definition->version->toString(),
            'status: '.$definition->status->value,
            'template.source: '.(string) ($definition->template['source'] ?? 'inline'),
        ]);
    }

    private static function templateSourceNote(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition !== null && ($definition->template['source'] ?? '') === 'legacy_prompt_content') {
            return (string) __('seo-content-ai::prompt_hooks.hook_legacy_prompt_template_note');
        }

        return (string) __('seo-content-ai::prompt_hooks.hook_template_owns_prompt');
    }

    public static function usesLegacyPromptTemplate(string $hookKey, string $version): bool
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition === null) {
            return false;
        }

        $source = trim((string) ($definition->template['source'] ?? ''));
        $mode = trim((string) ($definition->template['mode'] ?? ''));

        return $source === 'legacy_prompt_content' || $mode === 'legacy_prompt_content';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function presentationFor(string $hookKey): ?array
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return null;
        }

        try {
            return app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService::class)
                ->forHook($hookKey);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function presentationHasInstructions(string $hookKey): bool
    {
        $view = self::presentationFor($hookKey);

        return is_array($view) && ($view['default_instructions'] ?? []) !== [];
    }

    private static function presentationHasOutput(string $hookKey): bool
    {
        $view = self::presentationFor($hookKey);

        return is_array($view) && ($view['output_format'] ?? []) !== [];
    }

    private static function presentationHasInputs(string $hookKey): bool
    {
        $view = self::presentationFor($hookKey);

        return is_array($view) && ($view['inputs'] ?? []) !== [];
    }

    private static function presentationHasNotes(string $hookKey): bool
    {
        $view = self::presentationFor($hookKey);

        return is_array($view) && ($view['notes'] ?? []) !== [];
    }

    private static function presentationInstructionsHtml(string $hookKey): HtmlString
    {
        $view = self::presentationFor($hookKey);
        if ($view === null) {
            return new HtmlString('');
        }

        return new HtmlString(
            app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService::class)
                ->formatBulletHtml($view['default_instructions']),
        );
    }

    private static function presentationOutputHtml(string $hookKey): HtmlString
    {
        $view = self::presentationFor($hookKey);
        if ($view === null) {
            return new HtmlString('');
        }

        return new HtmlString(
            app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService::class)
                ->formatBulletHtml($view['output_format']),
        );
    }

    private static function presentationInputsHtml(string $hookKey): HtmlString
    {
        $view = self::presentationFor($hookKey);
        if ($view === null) {
            return new HtmlString('');
        }

        return new HtmlString(
            app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService::class)
                ->formatInputsHtml($view['inputs']),
        );
    }

    private static function presentationNotesHtml(string $hookKey): HtmlString
    {
        $view = self::presentationFor($hookKey);
        if ($view === null) {
            return new HtmlString('');
        }

        return new HtmlString(
            app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService::class)
                ->formatBulletHtml($view['notes']),
        );
    }

    private static function isMarkdownSectionsHook(string $hookKey, string $version): bool
    {
        $definition = self::resolveDefinition($hookKey, $version);

        return $definition !== null && $definition->outputSchema->isMarkdownSections();
    }

    private static function markdownSectionsContract(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition === null || ! $definition->outputSchema->isMarkdownSections()) {
            return '—';
        }

        $lines = [];
        foreach ($definition->outputSchema->sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $task = $section['task'] ?? null;
            $label = trim((string) ($section['label'] ?? $section['key'] ?? ''));
            $start = (string) ($section['start_marker'] ?? '');
            $end = (string) ($section['end_marker'] ?? '');
            $port = (string) ($section['output_port'] ?? '');
            $taskPrefix = $task !== null && $task !== '' ? 'Task '.$task.' — ' : '';
            $lines[] = $taskPrefix.$label;
            $lines[] = $start.' ... '.$end;
            if ($port !== '') {
                $lines[] = 'output_port: '.$port;
            }
            $lines[] = '';
        }

        $totalPort = $definition->outputSchema->totalPort !== ''
            ? $definition->outputSchema->totalPort
            : 'total';
        $lines[] = 'Total (AI) → '.$totalPort.' / out_main';

        return trim(implode("\n", $lines));
    }

    private static function inputMappingHelp(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition === null) {
            return '—';
        }

        $lines = [(string) __('seo-content-ai::prompt_hooks.input_mapping_hint')];
        foreach ($definition->inputSchema->fields as $field => $schema) {
            $req = is_array($schema) && ($schema['required'] ?? false) === true ? ' *' : '';
            $lines[] = "{$field}{$req} ← {{".$field.'}}';
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<Forms\Components\Component>
     */
    private static function settingsFields(string $hookKey, string $version): array
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition === null) {
            return [];
        }

        $fields = [];
        foreach ($definition->settingsSchema as $key => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            $fields[] = self::settingField((string) $key, $schema);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function settingField(string $key, array $schema): Forms\Components\Component
    {
        $labelKey = (string) ($schema['label_key'] ?? '');
        $label = $labelKey !== ''
            ? (string) __('seo-content-ai::'.$labelKey)
            : $key;

        $type = (string) ($schema['type'] ?? 'string');

        if (in_array($type, ['boolean', 'bool'], true)) {
            return Forms\Components\Toggle::make('hook_settings.'.$key)
                ->label($label)
                ->default((bool) ($schema['default'] ?? false))
                ->live();
        }

        if (in_array($type, ['integer', 'int', 'number', 'float'], true)) {
            $input = Forms\Components\TextInput::make('hook_settings.'.$key)
                ->label($label)
                ->numeric()
                ->required()
                ->default($schema['default'] ?? null)
                ->live(onBlur: true);

            if (isset($schema['min'])) {
                $input->minValue((float) $schema['min']);
            }
            if (isset($schema['max'])) {
                $input->maxValue((float) $schema['max']);
            }

            return $input;
        }

        return Forms\Components\TextInput::make('hook_settings.'.$key)
            ->label($label)
            ->default($schema['default'] ?? null)
            ->live(onBlur: true);
    }
}
