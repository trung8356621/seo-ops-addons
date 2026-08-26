<?php

declare(strict_types=1); // @codeCoverageIgnore

namespace Omnichannel\Addons\AiPrompt\Filament\Resources;




use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;
use Omnichannel\Addons\Seo\Filament\Resources\SeoPanelResource;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsOverview;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource\Pages;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookFormSchema;
use Omnichannel\Addons\AiPrompt\Services\AiModelsReadinessService;
use Omnichannel\Addons\AiPrompt\Support\PromptLoaiSanPhamVariable;
use Omnichannel\Addons\AiPrompt\Support\PromptSiteContextVariable;
use Omnichannel\Addons\AiPrompt\Support\PromptVariableSync;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class PromptResource extends SeoPanelResource
{
    protected static ?string $model = SeoPrompt::class;

    protected static ?string $slug = 'prompts';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = 'Prompt management';

    protected static ?string $modelLabel = 'Prompt';

    protected static ?string $pluralModelLabel = 'Prompts';

    protected static ?int $navigationSort = 14;

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canCreate(): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.prompt_management');
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.prompt');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.prompts');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(12)
                    ->schema([
                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\Section::make(__('seo-content-ai::filament.prompt.general_info'))
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label(__('seo-content-ai::filament.prompt.name'))
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\Textarea::make('description')
                                            ->label(__('seo-content-ai::filament.prompt.description'))
                                            ->rows(2)
                                            ->columnSpanFull(),
                                        Forms\Components\Radio::make('tools')
                                            ->label(__('seo-content-ai::filament.prompt.tool'))
                                            ->options(fn (): array => \Omnichannel\Addons\Media\Support\ImageToolType::promptSelectOptions())
                                            ->default('default')
                                            ->inline()
                                            ->live(),
                                    ]),
                                Forms\Components\Section::make(__('seo-content-ai::filament.prompt.execution_profile_section'))
                                    ->schema([
                                        Forms\Components\Placeholder::make('execution_profile_display')
                                            ->label(__('seo-content-ai::filament.prompt.execution_profile'))
                                            ->content(fn (Get $get): HtmlString => self::executionProfileDisplayHtml(
                                                (string) ($get('hook_key') ?? ''),
                                                (string) ($get('tools') ?? 'default'),
                                            )),
                                    ]),
                                ...PromptHookFormSchema::section(),
                            ])
                            ->columnSpan(['default' => 12, 'lg' => 4]),

                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\Section::make(
                                    function (Get $get): string {
                                        $hookKey = (string) ($get('hook_key') ?? '');
                                        if (blank($hookKey)) {
                                            return (string) __('seo-content-ai::filament.prompt.content_markdown');
                                        }

                                        $legacy = PromptHookFormSchema::usesLegacyPromptTemplate(
                                            $hookKey,
                                            (string) ($get('hook_version') ?? ''),
                                        );

                                        return $legacy
                                            ? (string) __('seo-content-ai::filament.prompt.content_prompt_own')
                                            : (string) __('seo-content-ai::filament.prompt.content_managed_by_hook');
                                    }
                                )
                                    ->description(function (Get $get): string {
                                        $hookKey = (string) ($get('hook_key') ?? '');
                                        if (blank($hookKey)) {
                                            return (string) __('seo-content-ai::filament.prompt.content_markdown_hint');
                                        }

                                        $legacy = PromptHookFormSchema::usesLegacyPromptTemplate(
                                            $hookKey,
                                            (string) ($get('hook_version') ?? ''),
                                        );

                                        return $legacy
                                            ? (string) __('seo-content-ai::filament.prompt.content_prompt_own_hint')
                                            : (string) __('seo-content-ai::filament.prompt.content_prompt_locked_hint');
                                    })
                                    ->schema([
                                        Forms\Components\Placeholder::make('hook_inline_template_notice')
                                            ->label('')
                                            ->content(new \Illuminate\Support\HtmlString(
                                                '<p class="text-sm text-gray-600 dark:text-gray-300">'
                                                .e((string) __('seo-content-ai::filament.prompt.content_prompt_locked_body'))
                                                .'</p>'
                                            ))
                                            ->visible(fn (Get $get): bool => filled($get('hook_key'))
                                                && ! PromptHookFormSchema::usesLegacyPromptTemplate(
                                                    (string) ($get('hook_key') ?? ''),
                                                    (string) ($get('hook_version') ?? ''),
                                                )),
                                        Forms\Components\MarkdownEditor::make('markdown_content')
                                            ->label('')
                                            ->required(fn (Get $get): bool => blank($get('hook_key'))
                                                || PromptHookFormSchema::usesLegacyPromptTemplate(
                                                    (string) ($get('hook_key') ?? ''),
                                                    (string) ($get('hook_version') ?? ''),
                                                ))
                                            ->disabled(fn (Get $get): bool => filled($get('hook_key'))
                                                && ! PromptHookFormSchema::usesLegacyPromptTemplate(
                                                    (string) ($get('hook_key') ?? ''),
                                                    (string) ($get('hook_version') ?? ''),
                                                ))
                                            ->hidden(fn (Get $get): bool => filled($get('hook_key'))
                                                && ! PromptHookFormSchema::usesLegacyPromptTemplate(
                                                    (string) ($get('hook_key') ?? ''),
                                                    (string) ($get('hook_version') ?? ''),
                                                ))
                                            ->dehydrated(true)
                                            ->live(onBlur: true)
                                            ->columnSpanFull()
                                            ->minHeight('280px')
                                            ->maxHeight('600px')
                                            ->extraAttributes([
                                                'class' => 'seo-prompt-markdown-editor',
                                            ])
                                            ->toolbarButtons([
                                                'bold',
                                                'italic',
                                                'bulletList',
                                                'orderedList',
                                                'blockquote',
                                                'link',
                                                'undo',
                                                'redo',
                                            ])
                                            ->placeholder(
                                                "# Role\nYou are an expert...\n\n"
                                                ."# Context\nSystem...\n\n"
                                                ."# Task: Main image\nCapture product image...\n\n"
                                                ."# Sub-task: Side shot\n..."
                                            ),
                                    ]),

                                Forms\Components\Section::make(__('seo-content-ai::filament.prompt.runtime_rules_title'))
                                    ->description(__('seo-content-ai::filament.prompt.runtime_rules_description'))
                                    ->schema([
                                        Forms\Components\Placeholder::make('composed_prompt_preview')
                                            ->label('')
                                            ->extraAttributes(['class' => 'seo-prompt-runtime-rules-panel'])
                                            ->content(fn (Get $get): \Illuminate\Support\HtmlString => PromptHookFormSchema::composedPreviewHtml($get)),
                                        Forms\Components\Toggle::make('composed_preview_expanded')
                                            ->label(__('seo-content-ai::filament.prompt.composed_preview_expand'))
                                            ->helperText(__('seo-content-ai::filament.prompt.composed_preview_expand_help'))
                                            ->default(false)
                                            ->live()
                                            ->dehydrated(false),
                                    ])
                                    ->visible(fn (Get $get): bool => filled($get('hook_key'))
                                        || filled(trim((string) ($get('markdown_content') ?? '')))),

                                ...PromptHookFormSchema::guidanceSection(),

                                Forms\Components\Section::make(__('seo-content-ai::filament.prompt.variables'))
                                    ->description(__('seo-content-ai::filament.prompt.variables_hint'))
                                    ->schema([
                                        Forms\Components\Placeholder::make('system_default_variables')
                                            ->label(__('seo-content-ai::filament.prompt.variables_system_title'))
                                            ->content(fn (Get $get): \Illuminate\Support\HtmlString => self::systemDefaultVariablesHtml($get))
                                            ->visible(fn (Get $get): bool => self::systemDefaultVariableRows($get) !== []),
                                        Forms\Components\Repeater::make('variables')
                                            ->label(__('seo-content-ai::filament.prompt.variables_custom_title'))
                                            ->schema([
                                                Forms\Components\TextInput::make('name')
                                                    ->label(__('seo-content-ai::filament.prompt.variable_name'))
                                                    ->required()
                                                    ->maxLength(128),
                                                Forms\Components\TextInput::make('description')
                                                    ->label(__('seo-content-ai::filament.prompt.variable_note'))
                                                    ->maxLength(255),
                                            ])
                                            ->defaultItems(0)
                                            ->addActionLabel(__('seo-content-ai::filament.prompt.add_variable'))
                                            ->reorderable()
                                            ->collapsible(),
                                    ])
                                    ->collapsed(false)
                                    ->collapsible(),

                                Forms\Components\Section::make(__('seo-content-ai::filament.prompt.post_processing.title'))
                                    ->description(__('seo-content-ai::filament.prompt.post_processing.description'))
                                    ->visible(fn (Get $get): bool => \Omnichannel\Addons\Media\Support\ImageToolType::fromMixed($get('tools'))->isImagePipeline())
                                    ->schema(self::postProcessingFormSchema()),
                            ])
                            ->columnSpan(['default' => 12, 'lg' => 8]),
                    ]),
            ]);
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function postProcessingFormSchema(): array
    {
        $gridMin = \Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing::GRID_SIZE_MIN;
        $gridMax = \Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing::GRID_SIZE_MAX;
        $gridDefault = \Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing::GRID_SIZE_DEFAULT;

        return [
            Forms\Components\View::make('seo-content-ai::filament.forms.prompt-post-processing-styles'),
            Forms\Components\Fieldset::make(__('seo-content-ai::filament.prompt.post_processing.quick_split'))
                ->schema([
                    Forms\Components\Toggle::make('settings.post_processing.split_enabled')
                        ->label(__('seo-content-ai::filament.prompt.post_processing.split_enable'))
                        ->inline(false)
                        ->live(),
                    Forms\Components\TextInput::make('settings.post_processing.split_grid_size')
                        ->label(__('seo-content-ai::filament.prompt.post_processing.grid_size'))
                        ->helperText(__('seo-content-ai::filament.prompt.post_processing.grid_size_helper'))
                        ->numeric()
                        ->integer()
                        ->minValue($gridMin)
                        ->maxValue($gridMax)
                        ->default($gridDefault)
                        ->required(fn (Get $get): bool => (bool) $get('settings.post_processing.split_enabled'))
                        ->visible(fn (Get $get): bool => (bool) $get('settings.post_processing.split_enabled'))
                        ->live(),
                    Forms\Components\Placeholder::make('split_grid_preview')
                        ->label('')
                        ->visible(fn (Get $get): bool => (bool) $get('settings.post_processing.split_enabled'))
                        ->content(function (Get $get) use ($gridDefault): string {
                            $raw = $get('settings.post_processing.split_grid_size');
                            $n = is_numeric($raw) ? (int) $raw : $gridDefault;
                            $n = \Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing::clampGridSize(
                                $n > 0 ? $n : $gridDefault,
                            );
                            $panels = $n * $n;

                            return __("seo-content-ai::filament.prompt.post_processing.grid_preview", [
                                'n' => $n,
                                'panels' => $panels,
                            ]);
                        }),
                    Forms\Components\Section::make(__('seo-content-ai::filament.prompt.post_processing.runtime_title'))
                        ->description(__('seo-content-ai::filament.prompt.post_processing.runtime_helper'))
                        ->schema([
                            Forms\Components\View::make('seo-content-ai::filament.forms.runtime-image-output-mode-preview')
                                ->viewData(function (Get $get): array {
                                    $config = \Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing::normalize([
                                        'split_enabled' => $get('settings.post_processing.split_enabled'),
                                        'split_grid_size' => $get('settings.post_processing.split_grid_size'),
                                        'resize_enabled' => $get('settings.post_processing.resize_enabled'),
                                        'resize_width' => $get('settings.post_processing.resize_width'),
                                        'resize_height' => $get('settings.post_processing.resize_height'),
                                    ]);
                                    $injector = app(\Omnichannel\Addons\AiPrompt\Services\ImageOutputModePromptInjector::class);

                                    return [
                                        'config' => $config,
                                        'summary' => $injector->summarize($config),
                                        'block' => $injector->buildBlock($config),
                                    ];
                                }),
                        ])
                        ->compact()
                        ->collapsible(false),
                    Forms\Components\Placeholder::make('manual_grid_warning')
                        ->label('')
                        ->visible(function (Get $get): bool {
                            $text = trim((string) ($get('markdown_content') ?? ''));
                            $enabled = (bool) $get('settings.post_processing.split_enabled');
                            $raw = $get('settings.post_processing.split_grid_size');
                            $n = is_numeric($raw)
                                ? \Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing::clampGridSize((int) $raw)
                                : \Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing::GRID_SIZE_DEFAULT;
                            $warnings = \Omnichannel\Addons\AiPrompt\Support\PromptManualGridWarning::detect($text, $enabled, $n);

                            return $warnings !== [];
                        })
                        ->content(function (Get $get): string {
                            $text = trim((string) ($get('markdown_content') ?? ''));
                            $enabled = (bool) $get('settings.post_processing.split_enabled');
                            $raw = $get('settings.post_processing.split_grid_size');
                            $n = is_numeric($raw)
                                ? \Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing::clampGridSize((int) $raw)
                                : \Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing::GRID_SIZE_DEFAULT;
                            $warnings = \Omnichannel\Addons\AiPrompt\Support\PromptManualGridWarning::detect($text, $enabled, $n);

                            return implode("\n", $warnings);
                        })
                        ->extraAttributes(['class' => 'text-warning-600 dark:text-warning-400']),
                    Forms\Components\Placeholder::make('split_hint')
                        ->label('')
                        ->content(__('seo-content-ai::filament.prompt.post_processing.split_hint')),
                ]),
            Forms\Components\Fieldset::make(__('seo-content-ai::filament.prompt.post_processing.quick_resize'))
                ->schema([
                    Forms\Components\Toggle::make('settings.post_processing.resize_enabled')
                        ->label(__('seo-content-ai::filament.prompt.post_processing.resize_enable'))
                        ->inline(false)
                        ->live(),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('settings.post_processing.resize_width')
                                ->label(__('seo-content-ai::filament.media_tools.width'))
                                ->numeric()
                                ->minValue(1)
                                ->placeholder('px'),
                            Forms\Components\TextInput::make('settings.post_processing.resize_height')
                                ->label(__('seo-content-ai::filament.media_tools.height'))
                                ->numeric()
                                ->minValue(1)
                                ->placeholder('px'),
                        ]),
                    Forms\Components\Placeholder::make('resize_hint')
                        ->label('')
                        ->content(__('seo-content-ai::filament.prompt.post_processing.resize_hint')),
                ]),
        ];
    }

    public static function executionProfileDisplayHtml(string $hookKey, string $toolType = 'default'): HtmlString
    {
        $profile = app(\Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver::class)
            ->resolve(null, $hookKey !== '' ? $hookKey : null, $toolType);

        $aiCenterUrl = '';
        try {
            $aiCenterUrl = (string) \Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter::getUrl();
        } catch (\Throwable) {
            $aiCenterUrl = '';
        }

        $html = '<div class="space-y-2">'
            .'<span class="inline-flex items-center rounded-md bg-gray-100 dark:bg-gray-800 px-2.5 py-1 text-sm font-medium text-gray-800 dark:text-gray-100">'
            .e($profile->displayName())
            .'</span>'
            .'<p class="text-sm text-gray-600 dark:text-gray-300">'
            .e((string) __('seo-content-ai::filament.prompt.execution_profile_hint'))
            .'</p>';

        if ($aiCenterUrl !== '') {
            $html .= '<p class="text-sm">'
                .'<a href="'.e($aiCenterUrl).'" class="text-primary-600 dark:text-primary-400 hover:underline font-medium">'
                .e((string) __('seo-content-ai::filament.prompt.open_ai_center'))
                .'</a>'
                .'</p>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    public static function markdownFromParts(Collection $parts): string
    {
        $blocks = [];

        foreach ($parts as $part) {
            $role = strtolower(trim((string) ($part->role ?? '')));
            $heading = match ($role) {
                'role' => 'Role',
                'context' => 'Context',
                'task' => 'Task',
                'sub_task' => 'Sub-task',
                'constraints' => 'Constraints',
                'formatting' => 'Output format',
                'global_constraints' => 'Global constraints',
                default => ucfirst($role !== '' ? $role : 'context'),
            };

            $name = trim((string) ($part->name ?? ''));
            if (in_array($role, ['task', 'sub_task'], true) && $name !== '') {
                $heading .= ': '.$name;
            }

            $content = trim((string) ($part->content ?? ''));
            if ($content === '') {
                continue;
            }

            $block = '# '.$heading."\n".$content;
            $meta = is_array($part->meta ?? null) ? $part->meta : [];

            $rules = trim((string) ($meta['rules'] ?? ''));
            if ($rules !== '') {
                $block .= "\n\nRules:\n".$rules;
            }

            if ($role === 'sub_task') {
                $specific = trim((string) ($meta['specific_constraints'] ?? ''));
                if ($specific !== '') {
                    $block .= "\n\nSpecific constraints (sub-prompt):\n".$specific;
                }
            }

            $blocks[] = $block;
        }

        return implode("\n\n", $blocks);
    }

    public static function promptUsesInputVariable(SeoPrompt $prompt): bool
    {
        $declared = collect(is_array($prompt->variables) ? $prompt->variables : []);

        if ($declared->contains(static fn ($row): bool => trim((string) ($row['name'] ?? '')) === 'input')) {
            return true;
        }

        return in_array('input', self::extractVariableNamesFromMarkdown((string) ($prompt->markdown_content ?? '')), true);
    }

    /**
     * Hook / markdown defaults shown read-only in Variables (not deletable).
     *
     * @return list<array{name: string, description: string, locked: bool}>
     */
    public static function systemDefaultVariableRows(Get $get): array
    {
        $hookKey = trim((string) ($get('hook_key') ?? ''));
        $markdown = (string) ($get('markdown_content') ?? '');
        $defaults = self::defaultVariableLabels();
        $rows = [];

        if ($hookKey !== '') {
            try {
                $presentation = app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService::class)
                    ->forHook($hookKey);
            } catch (\Throwable) {
                $presentation = null;
            }
            $inputs = is_array($presentation['inputs'] ?? null) ? $presentation['inputs'] : [];
            foreach ($inputs as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['key'] ?? $row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $desc = trim((string) ($row['label'] ?? $defaults[$name] ?? ''));
                if (! empty($row['required'])) {
                    $desc = $desc !== '' ? $desc.' (required)' : 'required';
                }
                $rows[$name] = [
                    'name' => $name,
                    'description' => $desc !== '' ? $desc : 'Hook default (không xóa được)',
                    'locked' => true,
                ];
            }
        }

        foreach (self::extractVariableNamesFromMarkdown($markdown) as $name) {
            if (isset($rows[$name])) {
                continue;
            }
            if (PromptLoaiSanPhamVariable::isLoaiSanPhamName($name)
                || PromptSiteContextVariable::isName($name)
                || strtoupper($name) === 'PARENT_RESULT') {
                continue;
            }
            $rows[$name] = [
                'name' => $name,
                'description' => (string) ($defaults[$name] ?? 'Từ Markdown — biến mặc định (không xóa được)'),
                'locked' => true,
            ];
        }

        foreach (self::defaultRuntimeVariableNames() as $name) {
            if (isset($rows[$name])) {
                continue;
            }
            $rows[$name] = [
                'name' => $name,
                'description' => (string) ($defaults[$name] ?? 'Runtime default (không xóa được)'),
                'locked' => true,
            ];
        }

        return array_values($rows);
    }

    public static function systemDefaultVariablesHtml(Get $get): HtmlString
    {
        $rows = self::systemDefaultVariableRows($get);
        if ($rows === []) {
            return new HtmlString('');
        }

        $items = [];
        foreach ($rows as $row) {
            $name = e('{{'.$row['name'].'}}');
            $desc = e((string) $row['description']);
            $items[] = '<li class="text-sm"><code class="text-xs">'.$name.'</code>'
                .' <span class="text-gray-500 dark:text-gray-400">— '.$desc.'</span>'
                .' <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-600 dark:bg-gray-800 dark:text-gray-300">'
                .e((string) __('seo-content-ai::filament.prompt.variables_locked_badge'))
                .'</span></li>';
        }

        $note = e((string) __('seo-content-ai::filament.prompt.variables_system_note'));

        return new HtmlString(
            '<p class="mb-2 text-xs text-gray-500 dark:text-gray-400">'.$note.'</p>'
            .'<ul class="list-disc space-y-1 pl-5">'.implode('', $items).'</ul>',
        );
    }

    /**
     * @return array<int, array{name: string, label: string, description: ?string}>
     */
    public static function variableDefinitionsForPrompt(SeoPrompt $prompt): array
    {
        $defaults = self::defaultVariableLabels();
        $declared = collect(is_array($prompt->variables) ? $prompt->variables : []);

        $names = $declared->pluck('name')
            ->filter()
            ->merge(self::extractVariableNamesFromMarkdown((string) ($prompt->markdown_content ?? '')))
            ->unique()
            ->values();

        return $names
            ->reject(static fn (string $name): bool => PromptLoaiSanPhamVariable::isLoaiSanPhamName($name)
                || PromptSiteContextVariable::isName($name)
                || strtoupper($name) === 'PARENT_RESULT')
            ->map(static function (string $name) use ($declared, $defaults): array {
                $row = $declared->firstWhere('name', $name);

                return [
                    'name' => $name,
                    'label' => $defaults[$name] ?? $name,
                    'description' => filled($row['description'] ?? null) ? (string) $row['description'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Loại biến mặc định khỏi repeater khai báo (vẫn dùng được trong nội dung prompt).
     *
     * @param  array<int, array<string, mixed>>|null  $variables
     * @return array<int, array<string, mixed>>
     */
    public static function sanitizeDeclaredVariables(?array $variables): array
    {
        return collect($variables ?? [])
            ->filter(static function (array $row): bool {
                $name = trim((string) ($row['name'] ?? ''));

                return $name !== ''
                    && ! PromptLoaiSanPhamVariable::isLoaiSanPhamName($name)
                    && ! PromptSiteContextVariable::isName($name);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function defaultVariableLabels(): array
    {
        return [
            'input' => 'Input from connected edge (SEO Flow)',
            'post_title' => 'Article title',
            'title' => 'Article title',
            'post_content' => 'Article content',
            'focus_keyword' => 'Focus keyword',
            'post_excerpt' => 'Excerpt',
            'comment_count' => 'Số bình luận/review cần gen (mặc định 3; không liên quan Limits.max_previous_outputs_items)',
            'site_domain' => 'Website domain',
            'site_short_description' => 'Website short description (domain)',
            'site_cta' => 'Website CTA / contact (domain) — includes [phone], [website], … placeholders for AI',
            'site_links' => 'Link list (deprecated — luôn rỗng; dùng đồng bộ keyword + gợi ý editor)',
            'tone' => 'Giọng văn (domain override, fallback SEO → Tùy chỉnh → Prompt)',
            'article_length' => 'Độ dài bài theo post_type hiện tại (số chữ, settings)',
            'article_length_product' => 'Độ dài bài — product (mặc định 1000)',
            'article_length_default' => 'Độ dài bài — các loại khác (mặc định 2000)',
            'keyword_density' => 'Mật độ từ khóa theo post_type hiện tại',
            'keyword_density_product' => 'Mật độ từ khóa — product',
            'keyword_density_default' => 'Mật độ từ khóa — các loại khác',
            'language' => 'Ngôn ngữ (Polylang bài viết hoặc mặc định SEO → Tùy chỉnh → Prompt)',
            'loai_san_pham' => 'Product category (product_cat) - default runtime variable from domain -> product_cat',
        ];
    }

    /**
     * Biến luôn có trong menu chèn / JSON preview (không cần khai báo trong repeater).
     *
     * @return list<string>
     */
    public static function defaultRuntimeVariableNames(): array
    {
        return array_values(array_unique([
            PromptLoaiSanPhamVariable::NAME,
            ...PromptSiteContextVariable::names(),
        ]));
    }

    /**
     * @return array<int, string>
     */
    public static function extractVariableNamesFromMarkdown(string $markdown): array
    {
        return PromptVariableSync::extractNames($markdown);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $declared
     * @return array<int, array{name: string, description: ?string}>
     */
    public static function mergeVariablesFromMarkdown(string $markdown, ?array $declared): array
    {
        return PromptVariableSync::mergeFromMarkdown($markdown, $declared);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('seo-content-ai::filament.prompt.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('hook_key')
                    ->label(__('seo-content-ai::filament.prompt.hook'))
                    ->formatStateUsing(function (?string $state): string {
                        if ($state === null || trim($state) === '') {
                            return (string) __('seo-content-ai::filament.prompt.hook_unassigned');
                        }
                        $key = trim($state);
                        $catalog = app(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog::class);
                        if ($catalog->isLegacyCompatibilityHook($key)) {
                            return $catalog->labelWithHookKey('Rewrite article content (Legacy)', $key);
                        }
                        try {
                            $name = $catalog->latestPinnedOrFail($key)->name;

                            return $catalog->labelWithHookKey($name !== '' ? $name : $key, $key);
                        } catch (\Throwable) {
                            try {
                                $name = app(\Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookRegistry::class)
                                    ->get($key)
                                    ->label();

                                return $catalog->labelWithHookKey($name, $key);
                            } catch (\Throwable) {
                                return '['.$key.']';
                            }
                        }
                    })
                    ->badge()
                    ->color(fn (?string $state): string => app(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog::class)
                        ->isLegacyCompatibilityHook((string) $state)
                        ? 'warning'
                        : 'gray')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('routing_profile_key')
                    ->label(__('seo-content-ai::filament.prompt.resolved_profile'))
                    ->state(function (SeoPrompt $record): string {
                        $profile = app(\Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver::class)
                            ->resolve($record);

                        return $profile->displayName();
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('aiConnection.name')
                    ->label(__('seo-content-ai::filament.prompt.ai_connection'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('usage')
                    ->label(__('seo-content-ai::filament.prompt.usage'))
                    ->state(function (SeoPrompt $record): string {
                        return app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptUsageLocator::class)
                            ->badge((int) $record->id)['badge'];
                    })
                    ->tooltip(function (SeoPrompt $record): ?string {
                        return app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptUsageLocator::class)
                            ->badge((int) $record->id)['tooltip'];
                    })
                    ->badge()
                    ->color(function (SeoPrompt $record): string {
                        $kind = app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptUsageLocator::class)
                            ->badge((int) $record->id)['kind'];

                        return match ($kind) {
                            'workflow' => 'info',
                            'settings' => 'success',
                            'mixed' => 'warning',
                            default => 'gray',
                        };
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('prompt_results_count')
                    ->label(__('seo-content-ai::filament.prompt.usage_count'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('prompt_results_max_started_at')
                    ->label(__('seo-content-ai::filament.prompt.last_used'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('seo-content-ai::filament.prompt.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('seo-content-ai::filament.prompt.updated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('hook_key')
                    ->label(__('seo-content-ai::filament.prompt.hook'))
                    ->options(fn (): array => app(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog::class)->selectOptions())
                    ->searchable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('test')
                    ->label(fn (SeoPrompt $record): string => app(AiModelsReadinessService::class)->isPromptReady($record)
                        ? 'Test'
                        : __('seo-content-ai::filament.prompt.sync_model'))
                    ->icon(fn (SeoPrompt $record): string => app(AiModelsReadinessService::class)->isPromptReady($record)
                        ? 'heroicon-o-play'
                        : 'heroicon-o-cpu-chip')
                    ->color(fn (SeoPrompt $record): string => app(AiModelsReadinessService::class)->isPromptReady($record)
                        ? 'success'
                        : 'warning')
                    ->url(fn (SeoPrompt $record): string => app(AiModelsReadinessService::class)->isPromptReady($record)
                        ? static::getUrl('test', ['record' => $record])
                        : SeoSettingsOverview::getUrl()),
                Tables\Actions\Action::make('duplicate')
                    ->label(__('seo-content-ai::filament.prompt.duplicate'))
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (SeoPrompt $record): void {
                        $copy = $record->replicate([
                            'deleted_at',
                        ]);
                        $copy->name = trim((string) $record->name).' (copy)';
                        $copy->is_active = true;
                        $wasLegacy = app(\Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog::class)
                            ->isLegacyCompatibilityHook((string) ($record->hook_key ?? ''));
                        if ($wasLegacy) {
                            // Phase 1.0: duplicate không giữ legacy rewrite hook.
                            $copy->hook_key = \Omnichannel\Addons\Content\Services\ArticleWritingExecutionService::HOOK_KEY;
                            $copy->hook_version = '0.1.0';
                        }
                        $copy->save();

                        \Filament\Notifications\Notification::make()
                            ->title(__('seo-content-ai::filament.prompt.duplicate_success'))
                            ->body($wasLegacy
                                ? (string) __('seo-content-ai::filament.prompt.duplicate_legacy_remapped')
                                : null)
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->form(function (SeoPrompt $record): array {
                        $locator = app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptUsageLocator::class);
                        if (! $locator->isReferenced((int) $record->id)) {
                            return [];
                        }

                        $lines = $locator->summarize((int) $record->id);

                        return [
                            Forms\Components\Placeholder::make('usage_list')
                                ->label(__('seo-content-ai::filament.prompt.delete_in_use_title'))
                                ->content(new \Illuminate\Support\HtmlString(
                                    '<ul class="list-disc pl-5 text-sm space-y-1">'
                                    .implode('', array_map(
                                        static fn (string $line): string => '<li>'.e($line).'</li>',
                                        $lines,
                                    ))
                                    .'</ul>'
                                )),
                            Forms\Components\Checkbox::make('force_detach')
                                ->label(__('seo-content-ai::filament.prompt.force_detach'))
                                ->helperText(__('seo-content-ai::filament.prompt.force_detach_help'))
                                ->required(),
                        ];
                    })
                    ->before(function (SeoPrompt $record, array $data): void {
                        $guard = app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptDeleteGuard::class);
                        $locator = app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptUsageLocator::class);
                        if (! $locator->isReferenced((int) $record->id)) {
                            return;
                        }
                        if (! (bool) ($data['force_detach'] ?? false)) {
                            $guard->assertDeletable((int) $record->id);
                        }
                        $guard->detachUsages((int) $record->id);
                    }),
            ])
            ->bulkActions(static::seoPanelBulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records): void {
                            $guard = app(\Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptDeleteGuard::class);
                            foreach ($records as $record) {
                                $guard->assertDeletable((int) $record->id);
                            }
                        }),
                ]),
            ]));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with('aiConnection')
            ->withCount('promptResults')
            ->withMax('promptResults', 'started_at');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        // SoftDeletes scope giữ nguyên: prompt đã xóa không hiện lại list
        // (trước đây withoutGlobalScopes SoftDeletingScope → row còn, mất nút Xóa).
        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPrompts::route('/'),
            'create' => Pages\CreatePrompt::route('/create'),
            'edit' => Pages\EditPrompt::route('/{record}/edit'),
            'test' => Pages\TestPrompt::route('/{record}/test'),
        ];
    }
}
