<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Filament\Pages;

use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Media\Services\SeoImageModelPriorityOptionsService;
use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use Omnichannel\Addons\Media\Support\ImageModelInputLengthPolicy;
use Omnichannel\Addons\Seo\Support\RenderingPreference;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Content\Support\TypographyValidationLevel;
use Omnichannel\Addons\AiPrompt\Support\VisionValidationModelRouter;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * AI Advanced — model routing / validation. Không lẫn với Editor Media (Prompt|Workflow).
 */
class SeoSettingsAiAdvanced extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/ai-advanced';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'AI Advanced';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-ai-advanced';

    /** @var array<string, mixed> */
    public array $settingsData = [];

    public function mount(SeoCreateArticleSettingsService $settings): void
    {
        $this->settingsData = $settings->getSettings();
        $this->form->fill($this->settingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('seo-content-ai::filament.settings_ai_advanced.rendering_preference_section'))
                    ->description(__('seo-content-ai::filament.settings_ai_advanced.rendering_preference_description'))
                    ->schema([
                        Forms\Components\Radio::make(SeoCreateArticleSettingsService::KEY_RENDERING_PREFERENCE)
                            ->label(__('seo-content-ai::filament.settings_ai_advanced.rendering_preference'))
                            ->options(fn (): array => RenderingPreference::selectOptions())
                            ->default(RenderingPreference::Balanced->value)
                            ->inline()
                            ->helperText(__('seo-content-ai::filament.settings_ai_advanced.rendering_preference_hint')),
                    ]),

                Forms\Components\Section::make(__('seo-content-ai::filament.settings_ai_advanced.model_priority_section'))
                    ->description(__('seo-content-ai::filament.settings_ai_advanced.model_priority_description'))
                    ->schema([
                        Forms\Components\Placeholder::make('image_model_priority_rules')
                            ->label(__('seo-content-ai::filament.settings_workflows.image_model_priority_rules'))
                            ->helperText(__('seo-content-ai::filament.settings_workflows.image_model_priority_rules_note'))
                            ->content(function (): HtmlString {
                                $rows = ImageModelInputLengthPolicy::routingTableRows();
                                $html = '<div class="overflow-x-auto"><table class="w-full text-sm border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">'
                                    .'<thead class="bg-gray-50 dark:bg-gray-800 text-left">'
                                    .'<tr>'
                                    .'<th class="px-3 py-2 font-medium">'.e(__('seo-content-ai::filament.settings_workflows.image_model_table_range')).'</th>'
                                    .'<th class="px-3 py-2 font-medium">'.e(__('seo-content-ai::filament.settings_workflows.image_model_table_tier')).'</th>'
                                    .'<th class="px-3 py-2 font-medium">'.e(__('seo-content-ai::filament.settings_workflows.image_model_table_reason')).'</th>'
                                    .'</tr></thead><tbody>';

                                foreach ($rows as $row) {
                                    $tierLabel = ImageModelInputLengthPolicy::tierHint((string) $row['tier']);
                                    $html .= '<tr class="border-t border-gray-200 dark:border-gray-700">'
                                        .'<td class="px-3 py-2 whitespace-nowrap">'.e((string) $row['range']).'</td>'
                                        .'<td class="px-3 py-2">'.e($tierLabel).'</td>'
                                        .'<td class="px-3 py-2 text-gray-600 dark:text-gray-300">'.e((string) $row['reason']).'</td>'
                                        .'</tr>';
                                }

                                $html .= '</tbody></table></div>';

                                return new HtmlString($html);
                            }),
                        $this->imagePriorityRepeater(
                            SeoCreateArticleSettingsService::KEY_IMAGE_MODEL_PRIORITY,
                            __('seo-content-ai::filament.settings_ai_advanced.general_image_priority'),
                            __('seo-content-ai::filament.settings_ai_advanced.general_image_priority_hint'),
                        ),
                        $this->imagePriorityRepeater(
                            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MODEL_PRIORITY,
                            __('seo-content-ai::filament.settings_ai_advanced.typography_image_priority'),
                            __('seo-content-ai::filament.settings_ai_advanced.typography_image_priority_hint'),
                        ),
                        Forms\Components\Repeater::make(SeoCreateArticleSettingsService::KEY_VIDEO_MODEL_PRIORITY)
                            ->label(__('seo-content-ai::filament.settings_ai_advanced.video_model_priority'))
                            ->helperText(__('seo-content-ai::filament.settings_ai_advanced.video_model_priority_hint'))
                            ->schema([
                                Forms\Components\Select::make('slug')
                                    ->label(__('seo-content-ai::filament.settings_workflows.image_model_slug'))
                                    ->options(fn (): array => GoogleAiModelRegistry::videoSelectOptions())
                                    ->searchable()
                                    ->required()
                                    ->native(false),
                            ])
                            ->addActionLabel(__('seo-content-ai::filament.settings_ai_advanced.add_video_model'))
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(function (array $state): ?string {
                                $slug = trim((string) ($state['slug'] ?? ''));

                                return $slug !== ''
                                    ? (GoogleAiModelRegistry::videoSelectOptions()[$slug] ?? $slug)
                                    : __('seo-content-ai::filament.settings_workflows.new_image_model');
                            }),
                    ])
                    ->collapsible(),

                Forms\Components\Section::make(__('seo-content-ai::filament.settings_ai_advanced.validation_section'))
                    ->description(__('seo-content-ai::filament.settings_ai_advanced.validation_description'))
                    ->schema([
                        Forms\Components\Toggle::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_ENABLED)
                            ->label(__('seo-content-ai::filament.settings_workflows.typography_validation_enabled'))
                            ->default(true),
                        Forms\Components\Radio::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_LEVEL)
                            ->label(__('seo-content-ai::filament.settings_workflows.typography_validation_level'))
                            ->options(fn (): array => TypographyValidationLevel::selectOptions())
                            ->default(TypographyValidationLevel::Balanced->value)
                            ->inline(),
                        Forms\Components\Select::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_MODEL)
                            ->label(__('seo-content-ai::filament.settings_ai_advanced.validation_model'))
                            ->helperText(__('seo-content-ai::filament.settings_ai_advanced.validation_model_hint'))
                            ->options(fn (): array => $this->visionValidationModelOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder(__('seo-content-ai::filament.settings_ai_advanced.validation_model_default')),
                        Forms\Components\TextInput::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MAX_CANDIDATES)
                            ->label(__('seo-content-ai::filament.settings_workflows.typography_max_candidates'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3)
                            ->helperText(__('seo-content-ai::filament.settings_workflows.typography_max_candidates_hint')),
                        Forms\Components\TextInput::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_PASS_THRESHOLD)
                            ->label(__('seo-content-ai::filament.settings_workflows.typography_pass_threshold'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1)
                            ->step(0.01)
                            ->helperText(__('seo-content-ai::filament.settings_workflows.typography_pass_threshold_hint')),
                        Forms\Components\Toggle::make(SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK)
                            ->label(__('seo-content-ai::filament.settings_ai_advanced.typography_allow_general_fallback'))
                            ->helperText(__('seo-content-ai::filament.settings_ai_advanced.typography_allow_general_fallback_hint')),
                    ])
                    ->collapsible(),
            ])
            ->statePath('settingsData');
    }

    private function imagePriorityRepeater(string $key, string $label, string $helper): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make($key)
            ->label($label)
            ->helperText($helper)
            ->schema([
                Forms\Components\Select::make('slug')
                    ->label(__('seo-content-ai::filament.settings_workflows.image_model_slug'))
                    ->options(fn (SeoImageModelPriorityOptionsService $options): array => $options->imageModelSelectOptions())
                    ->searchable()
                    ->required()
                    ->native(false),
            ])
            ->addActionLabel(__('seo-content-ai::filament.settings_workflows.add_image_model'))
            ->reorderable()
            ->collapsible()
            ->itemLabel(function (array $state, SeoImageModelPriorityOptionsService $options): ?string {
                $slug = trim((string) ($state['slug'] ?? ''));
                if ($slug === '') {
                    return __('seo-content-ai::filament.settings_workflows.new_image_model');
                }

                return $options->labelForSlug($slug) ?? $slug;
            });
    }

    /**
     * @return array<string, string>
     */
    private function visionValidationModelOptions(): array
    {
        $router = new VisionValidationModelRouter();
        $options = [];
        foreach ($router->modelsToTry() as $slug) {
            $options[$slug] = GoogleAiModelRegistry::textSelectOptions()[$slug] ?? $slug;
        }

        return $options;
    }

    public function saveSettings(SeoCreateArticleSettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->saveSettings([
            SeoCreateArticleSettingsService::KEY_RENDERING_PREFERENCE => $data[SeoCreateArticleSettingsService::KEY_RENDERING_PREFERENCE] ?? null,
            SeoCreateArticleSettingsService::KEY_IMAGE_MODEL_PRIORITY => $data[SeoCreateArticleSettingsService::KEY_IMAGE_MODEL_PRIORITY] ?? null,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MODEL_PRIORITY => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MODEL_PRIORITY] ?? null,
            SeoCreateArticleSettingsService::KEY_VIDEO_MODEL_PRIORITY => $data[SeoCreateArticleSettingsService::KEY_VIDEO_MODEL_PRIORITY] ?? null,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_ENABLED => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_ENABLED] ?? true,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_LEVEL => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_LEVEL] ?? null,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_MODEL => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_VALIDATION_MODEL] ?? null,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MAX_CANDIDATES => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_MAX_CANDIDATES] ?? null,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_PASS_THRESHOLD => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_PASS_THRESHOLD] ?? null,
            SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK => $data[SeoCreateArticleSettingsService::KEY_TYPOGRAPHY_ALLOW_GENERAL_IMAGE_FALLBACK] ?? false,
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.settings_ai_advanced.saved'))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }
}
