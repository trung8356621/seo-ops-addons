<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Pages;

use Omnichannel\Addons\AiPrompt\Services\PromptLanguageVariableService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettingsPrompt extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/prompt';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Prompt settings';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-prompt';

    /** @var array<string, mixed> */
    public array $promptSettingsData = [];

    public function mount(SeoPromptSettingsService $settings): void
    {
        $raw = $settings->getSettings();

        $this->promptSettingsData = [
            SeoPromptSettingsService::KEY_TONE_TEXT => $raw[SeoPromptSettingsService::KEY_TONE_TEXT],
            SeoPromptSettingsService::KEY_TONE_OF_VOICE => array_map(
                static fn (string $tone): array => ['label' => $tone],
                $raw[SeoPromptSettingsService::KEY_TONE_OF_VOICE],
            ),
            SeoPromptSettingsService::KEY_ARTICLE_LENGTH_PRODUCT => $raw[SeoPromptSettingsService::KEY_ARTICLE_LENGTH_PRODUCT],
            SeoPromptSettingsService::KEY_ARTICLE_LENGTH_DEFAULT => $raw[SeoPromptSettingsService::KEY_ARTICLE_LENGTH_DEFAULT],
            SeoPromptSettingsService::KEY_KEYWORD_DENSITY_PRODUCT => $raw[SeoPromptSettingsService::KEY_KEYWORD_DENSITY_PRODUCT],
            SeoPromptSettingsService::KEY_KEYWORD_DENSITY_DEFAULT => $raw[SeoPromptSettingsService::KEY_KEYWORD_DENSITY_DEFAULT],
            SeoPromptSettingsService::KEY_DEFAULT_PROMPT_LANGUAGE => $raw[SeoPromptSettingsService::KEY_DEFAULT_PROMPT_LANGUAGE],
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MIN => $raw[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MIN],
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_RANGE => $raw[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_RANGE],
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MAX => $raw[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MAX],
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_COLUMNS => $raw[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_COLUMNS],
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MAX_COLUMNS => $raw[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MAX_COLUMNS],
        ];

        $this->form->fill($this->promptSettingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_prompt.tone_section'))
                    ->description(__('seo-content-ai::filament.settings_prompt.tone_section_description'))
                    ->schema([
                        Forms\Components\Textarea::make(SeoPromptSettingsService::KEY_TONE_TEXT)
                            ->label(__('seo-content-ai::filament.settings_prompt.tone_text'))
                            ->helperText(__('seo-content-ai::filament.settings_prompt.tone_text_hint'))
                            ->rows(4)
                            ->maxLength(4000)
                            ->columnSpanFull(),
                        Forms\Components\Repeater::make(SeoPromptSettingsService::KEY_TONE_OF_VOICE)
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->label(__('seo-content-ai::filament.settings_prompt.tone_label'))
                                    ->required()
                                    ->maxLength(120)
                                    ->columnSpanFull(),
                            ])
                            ->defaultItems(0)
                            ->addActionLabel(__('seo-content-ai::filament.settings_prompt.add_tone'))
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null)
                                ? (string) $state['label']
                                : __('seo-content-ai::filament.settings_prompt.new_tone')),
                    ]),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_prompt.content_rules_section'))
                    ->description(__('seo-content-ai::filament.settings_prompt.content_rules_description'))
                    ->schema([
                        Forms\Components\Fieldset::make(__('seo-content-ai::filament.settings_prompt.article_length_fieldset'))
                            ->schema([
                                Forms\Components\TextInput::make(SeoPromptSettingsService::KEY_ARTICLE_LENGTH_PRODUCT)
                                    ->label(__('seo-content-ai::filament.settings_prompt.article_length_product'))
                                    ->helperText('{{article_length_product}} · runtime: {{article_length}} (post_type = product)')
                                    ->required()
                                    ->maxLength(64),
                                Forms\Components\TextInput::make(SeoPromptSettingsService::KEY_ARTICLE_LENGTH_DEFAULT)
                                    ->label(__('seo-content-ai::filament.settings_prompt.article_length_default'))
                                    ->helperText('{{article_length_default}} · runtime: {{article_length}} (các loại khác)')
                                    ->required()
                                    ->maxLength(64),
                            ])
                            ->columns(2),
                        Forms\Components\Fieldset::make(__('seo-content-ai::filament.settings_prompt.keyword_density_fieldset'))
                            ->schema([
                                Forms\Components\Textarea::make(SeoPromptSettingsService::KEY_KEYWORD_DENSITY_PRODUCT)
                                    ->label(__('seo-content-ai::filament.settings_prompt.keyword_density_product'))
                                    ->helperText('{{keyword_density_product}} · runtime: {{keyword_density}} (post_type = product)')
                                    ->rows(3)
                                    ->maxLength(2000)
                                    ->columnSpanFull(),
                                Forms\Components\Textarea::make(SeoPromptSettingsService::KEY_KEYWORD_DENSITY_DEFAULT)
                                    ->label(__('seo-content-ai::filament.settings_prompt.keyword_density_default'))
                                    ->helperText('{{keyword_density_default}} · runtime: {{keyword_density}} (các loại khác)')
                                    ->rows(3)
                                    ->maxLength(2000)
                                    ->columnSpanFull(),
                            ]),
                    ]),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_prompt.language_section'))
                    ->description(__('seo-content-ai::filament.settings_prompt.language_section_description'))
                    ->schema([
                        Forms\Components\Select::make(SeoPromptSettingsService::KEY_DEFAULT_PROMPT_LANGUAGE)
                            ->label(__('seo-content-ai::filament.settings_prompt.default_prompt_language'))
                            ->helperText(__('seo-content-ai::filament.settings_prompt.default_prompt_language_hint'))
                            ->options(fn (): array => PromptLanguageVariableService::defaultLanguageSlugOptions())
                            ->searchable()
                            ->required()
                            ->native(false),
                    ]),
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_prompt.featured_snippet'))
                    ->description(__('seo-content-ai::filament.settings_prompt.featured_snippet_description'))
                    ->schema([
                        Forms\Components\TextInput::make(SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MIN)
                            ->label(__('seo-content-ai::filament.settings_prompt.rows_min'))
                            ->helperText(__('seo-content-ai::filament.settings_prompt.rows_min_hint'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(50)
                            ->required()
                            ->default(6),
                        Forms\Components\TextInput::make(SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_RANGE)
                            ->label(__('seo-content-ai::filament.settings_prompt.rows_range'))
                            ->helperText(__('seo-content-ai::filament.settings_prompt.rows_range_hint'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(50)
                            ->required()
                            ->default(8),
                        Forms\Components\TextInput::make(SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MAX)
                            ->label(__('seo-content-ai::filament.settings_prompt.rows_max'))
                            ->helperText(__('seo-content-ai::filament.settings_prompt.rows_max_hint'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(50)
                            ->required()
                            ->default(10),
                        Forms\Components\TextInput::make(SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_COLUMNS)
                            ->label(__('seo-content-ai::filament.settings_prompt.min_columns'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->required()
                            ->default(2),
                        Forms\Components\TextInput::make(SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MAX_COLUMNS)
                            ->label(__('seo-content-ai::filament.settings_prompt.max_columns'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->required()
                            ->default(5),
                    ])
                    ->columns(3),
            ])
            ->statePath('promptSettingsData');
    }

    public function savePromptSettings(SeoPromptSettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->saveSettings([
            SeoPromptSettingsService::KEY_TONE_TEXT => $data[SeoPromptSettingsService::KEY_TONE_TEXT] ?? '',
            SeoPromptSettingsService::KEY_TONE_OF_VOICE => $data[SeoPromptSettingsService::KEY_TONE_OF_VOICE] ?? [],
            SeoPromptSettingsService::KEY_ARTICLE_LENGTH_PRODUCT => $data[SeoPromptSettingsService::KEY_ARTICLE_LENGTH_PRODUCT] ?? '',
            SeoPromptSettingsService::KEY_ARTICLE_LENGTH_DEFAULT => $data[SeoPromptSettingsService::KEY_ARTICLE_LENGTH_DEFAULT] ?? '',
            SeoPromptSettingsService::KEY_KEYWORD_DENSITY_PRODUCT => $data[SeoPromptSettingsService::KEY_KEYWORD_DENSITY_PRODUCT] ?? '',
            SeoPromptSettingsService::KEY_KEYWORD_DENSITY_DEFAULT => $data[SeoPromptSettingsService::KEY_KEYWORD_DENSITY_DEFAULT] ?? '',
            SeoPromptSettingsService::KEY_DEFAULT_PROMPT_LANGUAGE => $data[SeoPromptSettingsService::KEY_DEFAULT_PROMPT_LANGUAGE] ?? 'vi',
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MIN => $data[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MIN] ?? 6,
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_RANGE => $data[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_RANGE] ?? 8,
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MAX => $data[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_ROWS_MAX] ?? 10,
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_COLUMNS => $data[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MIN_COLUMNS] ?? 2,
            SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MAX_COLUMNS => $data[SeoPromptSettingsService::KEY_FEATURED_SNIPPET_MAX_COLUMNS] ?? 5,
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_prompt.saved'))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
